<?php
/**
 * Ojeador Novedades: radar de oportunidades fuera del catálogo conocido.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.10.0
 */
defined('ABSPATH') || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Existing public seo_ legacy extension contract; renaming would break third-party callbacks and integrations.
final class SEO_Ojeador_Novedades {
    const DB_VERSION='0.1.0';
    const OPT_DB='seo_ojeador_novedades_db_version';
    const OPT_CURSOR='seo_ojeador_novedades_seed_cursor';
    const OPT_LAST='seo_ojeador_novedades_last_run';

    public static function init(){
        add_action('admin_post_seo_ojeador_novedades_scan',array(__CLASS__,'handle_scan'));
        add_action('admin_post_seo_ojeador_novedades_status',array(__CLASS__,'handle_status'));
        add_action('admin_post_seo_ojeador_novedades_export',array(__CLASS__,'handle_export'));
    }

    public static function table(){global $wpdb;return $wpdb->prefix.'seo_ojeador_opportunities';}

    public static function maybe_install(){
        global $wpdb;
        $table=self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema probe on an internal table; schema checks must not use stale cached data.
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
        if((string)get_option(self::OPT_DB,'')===self::DB_VERSION && $exists)return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            opportunity_key char(64) NOT NULL,
            title varchar(255) NOT NULL DEFAULT '',
            opportunity_type varchar(40) NOT NULL DEFAULT 'new_category',
            status varchar(32) NOT NULL DEFAULT 'detected',
            coverage_status varchar(32) NOT NULL DEFAULT 'absent',
            matched_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            matched_category varchar(255) NOT NULL DEFAULT '',
            cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cluster_name varchar(255) NOT NULL DEFAULT '',
            hub_primary_id bigint(20) unsigned NOT NULL DEFAULT 0,
            hub_primary_name varchar(255) NOT NULL DEFAULT '',
            score smallint(5) unsigned NOT NULL DEFAULT 0,
            trend_score smallint(5) unsigned NOT NULL DEFAULT 0,
            market_score smallint(5) unsigned NOT NULL DEFAULT 0,
            affinity_score smallint(5) unsigned NOT NULL DEFAULT 0,
            gap_score smallint(5) unsigned NOT NULL DEFAULT 0,
            trend_value int(10) unsigned NOT NULL DEFAULT 0,
            trend_label varchar(64) NOT NULL DEFAULT '',
            shopping_results int(10) unsigned NOT NULL DEFAULT 0,
            merchant_count int(10) unsigned NOT NULL DEFAULT 0,
            max_reviews int(10) unsigned NOT NULL DEFAULT 0,
            justification text NULL,
            sources_json longtext NULL,
            evidence_json longtext NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY opportunity_key (opportunity_key),
            KEY score (score),
            KEY status (status),
            KEY opportunity_type (opportunity_type),
            KEY hub_primary_id (hub_primary_id)
        ) {$charset};");
        update_option(self::OPT_DB,self::DB_VERSION,false);
    }

    private static function guard($action){
        if(!current_user_can('manage_options'))wp_die(esc_html__('No autorizado.','taxocommerce-scrappers'));
        check_admin_referer($action);
    }
    private static function redirect($args=array()){
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'seo-ojeador','ojeador_tab'=>'novedades'),$args),admin_url('admin.php')));
        exit;
    }
    private static function norm($v){
        $v=remove_accents(wp_strip_all_tags((string)$v));
        $v=function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);
        $v=preg_replace('/[^a-z0-9]+/u',' ',$v);
        return trim(preg_replace('/\s+/u',' ',(string)$v));
    }
    private static function tokens($v){
        $stop=array_flip(array('de','del','la','las','el','los','para','por','con','sin','y','o','en','un','una','unos','unas','al','que','herramienta','herramientas','profesional','profesionales'));
        $out=array();
        foreach(preg_split('/\s+/u',self::norm($v),-1,PREG_SPLIT_NO_EMPTY) as $t)if(strlen($t)>=3&&!isset($stop[$t]))$out[$t]=1;
        return array_keys($out);
    }
    private static function overlap($a,$b){
        $a=self::tokens($a);$b=self::tokens($b);
        if(!$a||!$b)return 0.0;
        $u=count(array_unique(array_merge($a,$b)));
        return $u?count(array_intersect($a,$b))/$u:0.0;
    }

    public static function seeds(){
        global $wpdb;
        $rel=$wpdb->prefix.'seo_relations';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- internal tables only.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live read of custom research/catalog tables; writes and cron modify these rows and there is no safe shared cache invalidation.
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT DISTINCT cl.ID cluster_id,cl.post_title cluster_name,hp.ID hub_primary_id,hp.post_title hub_primary_name
             FROM %i r
             INNER JOIN %i cl ON cl.ID=r.source_id
             INNER JOIN %i hp ON hp.ID=r.target_id
             WHERE r.source_type='cluster' AND r.target_type='hub_primary'
               AND r.relation_type IN ('cluster_to_primary','cluster_to_hub_primary')
               AND cl.post_status NOT IN ('trash','auto-draft')
               AND hp.post_status NOT IN ('trash','auto-draft')
             ORDER BY cl.post_title,hp.post_title", $rel, $wpdb->posts, $wpdb->posts), ARRAY_A);
        $out=array();
        foreach($rows as $r){
            if(empty($r['hub_primary_id'])||empty($r['hub_primary_name']))continue;
            $out[]=array(
                'cluster_id'=>absint($r['cluster_id']??0),
                'cluster_name'=>sanitize_text_field((string)($r['cluster_name']??'')),
                'hub_primary_id'=>absint($r['hub_primary_id']),
                'hub_primary_name'=>sanitize_text_field((string)$r['hub_primary_name']),
            );
        }
        return $out;
    }

    private static function categories(){
        static $cache=null;if(is_array($cache))return $cache;$cache=array();
        $terms=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        if(is_wp_error($terms))return $cache;
        foreach($terms as $t)if($t instanceof WP_Term)$cache[]=array('id'=>absint($t->term_id),'name'=>(string)$t->name,'norm'=>self::norm($t->name),'count'=>absint($t->count));
        return $cache;
    }

    private static function coverage($candidate){
        $n=self::norm($candidate);$best=null;$ratio=0.0;
        foreach(self::categories() as $c){
            if($n!==''&&$n===$c['norm'])return array('status'=>'covered','term_id'=>$c['id'],'name'=>$c['name'],'ratio'=>1.0,'count'=>$c['count']);
            $r=self::overlap($candidate,$c['name']);
            if($r>$ratio){$ratio=$r;$best=$c;}
        }
        if($best&&$ratio>=0.48)return array('status'=>'partial','term_id'=>$best['id'],'name'=>$best['name'],'ratio'=>round($ratio,3),'count'=>$best['count']);
        return array('status'=>'absent','term_id'=>0,'name'=>'','ratio'=>round($ratio,3),'count'=>0);
    }

    private static function trend_candidates($body,$limit){
        $out=array();$rows=(array)($body['related_queries']['rising']??array());
        foreach($rows as $r)if(is_array($r)&&!empty($r['query']))$out[]=array(
            'title'=>sanitize_text_field((string)$r['query']),
            'value'=>absint($r['extracted_value']??0),
            'label'=>sanitize_text_field((string)($r['value']??'')),
            'url'=>esc_url_raw((string)($r['link']??'')),
            'kind'=>'related_query_rising'
        );
        if(!$out){
            foreach((array)($body['related_topics']['rising']??array()) as $r){
                $topic=is_array($r['topic']??null)?$r['topic']:array();
                if(empty($topic['title']))continue;
                $out[]=array('title'=>sanitize_text_field((string)$topic['title']),'value'=>absint($r['extracted_value']??0),'label'=>sanitize_text_field((string)($r['value']??'')),'url'=>esc_url_raw((string)($r['link']??'')),'kind'=>'related_topic_rising');
            }
        }
        usort($out,static function($a,$b){return absint($b['value']??0)<=>absint($a['value']??0);});
        $seen=array();$clean=array();
        foreach($out as $r){$k=self::norm($r['title']??'');if($k===''||isset($seen[$k]))continue;$seen[$k]=1;$clean[]=$r;if(count($clean)>=$limit)break;}
        return $clean;
    }

    private static function shopping_metrics($body){
        $rows=array();
        foreach(array('shopping_results','inline_shopping_results','featured_shopping_results') as $k)foreach((array)($body[$k]??array()) as $r)if(is_array($r))$rows[]=$r;
        $merchants=array();$max_reviews=0;$sources=array();
        foreach($rows as $r){
            $merchant='';
            foreach(array('source','merchant','seller','store') as $k)if(!empty($r[$k])&&is_scalar($r[$k])){$merchant=sanitize_text_field((string)$r[$k]);break;}
            if($merchant!=='')$merchants[self::norm($merchant)]=1;
            $max_reviews=max($max_reviews,absint($r['reviews']??0));
            $url='';
            foreach(array('product_link','link') as $k)if(!empty($r[$k])&&is_scalar($r[$k])){$url=esc_url_raw((string)$r[$k]);if($url!=='')break;}
            if($url!==''&&count($sources)<4)$sources[]=array('type'=>'google_shopping','title'=>sanitize_text_field((string)($r['title']??'Producto Google Shopping')),'url'=>$url,'merchant'=>$merchant,'reviews'=>absint($r['reviews']??0));
        }
        return array('count'=>count($rows),'merchants'=>count($merchants),'max_reviews'=>$max_reviews,'sources'=>$sources);
    }

    private static function search_metrics($body){
        $rows=(array)($body['organic_results']??array());$sources=array();
        foreach($rows as $r){
            if(!is_array($r)||empty($r['link']))continue;
            $u=esc_url_raw((string)$r['link']);if($u==='')continue;
            $sources[]=array('type'=>'google_search','title'=>sanitize_text_field((string)($r['title']??'Resultado Google')),'url'=>$u,'snippet'=>sanitize_text_field((string)($r['snippet']??'')));
            if(count($sources)>=4)break;
        }
        return array('count'=>count($rows),'sources'=>$sources);
    }

    private static function trend_score($v,$label){
        $v=absint($v);$l=strtolower((string)$label);
        if(strpos($l,'breakout')!==false||strpos($l,'record')!==false||$v>=500)return 35;
        if($v>=200)return 32;if($v>=100)return 28;if($v>=50)return 24;if($v>=20)return 18;if($v>0)return 12;return 8;
    }
    private static function market_score($m){
        $s=min(15,absint($m['count']??0))+min(8,absint($m['merchants']??0)*2);$r=absint($m['max_reviews']??0);
        if($r>=1000)$s+=7;elseif($r>=300)$s+=6;elseif($r>=100)$s+=5;elseif($r>=30)$s+=4;elseif($r>=10)$s+=3;elseif($r>0)$s+=1;
        return min(30,$s);
    }
    private static function affinity_score($candidate,$seed,$search){
        $s=10;$context=trim((string)($seed['cluster_name']??'').' '.(string)($seed['hub_primary_name']??''));
        $ov=max(self::overlap($candidate,$context),self::overlap($candidate,(string)($seed['hub_primary_name']??'')));
        $s+=(int)round(min(8,$ov*12));$n=absint($search['count']??0);
        if($n>=8)$s+=7;elseif($n>=5)$s+=6;elseif($n>=3)$s+=5;elseif($n>=1)$s+=3;
        return min(25,$s);
    }
    private static function gap_score($coverage){return ($coverage['status']??'')==='absent'?10:(($coverage['status']??'')==='partial'?7:3);}
    private static function type_for($coverage,$affinity,$market){
        if(($coverage['status']??'')==='absent')return $affinity>=14?'new_category':'strategic_expansion';
        if(($coverage['status']??'')==='partial')return 'catalog_gap';
        if(absint($coverage['count']??0)===0)return 'catalog_gap';
        return $market>=12?'trending_product':'catalog_gap';
    }
    private static function type_label($t){$m=array('new_category'=>'Nueva categoría/familia','catalog_gap'=>'Hueco en catálogo','trending_product'=>'Producto/tecnología en tendencia','strategic_expansion'=>'Expansión estratégica');return $m[$t]??$t;}
    private static function coverage_label($s){$m=array('absent'=>'No encontrada','partial'=>'Cobertura parcial','covered'=>'Ya cubierta');return $m[$s]??$s;}

    private static function upsert($row){
        global $wpdb;$table=self::table();$now=gmdate('Y-m-d H:i:s');
        $key=hash('sha256',self::norm($row['title']??'').'|'.absint($row['hub_primary_id']??0));
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live read of custom research/catalog tables; writes and cron modify these rows and there is no safe shared cache invalidation.
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,status,first_seen_at FROM %i WHERE opportunity_key=%s LIMIT 1", $table,$key),ARRAY_A);
        $data=array(
            'opportunity_key'=>$key,'title'=>sanitize_text_field((string)($row['title']??'')),
            'opportunity_type'=>sanitize_key((string)($row['opportunity_type']??'new_category')),
            'status'=>$existing?sanitize_key((string)($existing['status']??'detected')):'detected',
            'coverage_status'=>sanitize_key((string)($row['coverage_status']??'absent')),
            'matched_term_id'=>absint($row['matched_term_id']??0),'matched_category'=>sanitize_text_field((string)($row['matched_category']??'')),
            'cluster_id'=>absint($row['cluster_id']??0),'cluster_name'=>sanitize_text_field((string)($row['cluster_name']??'')),
            'hub_primary_id'=>absint($row['hub_primary_id']??0),'hub_primary_name'=>sanitize_text_field((string)($row['hub_primary_name']??'')),
            'score'=>min(100,absint($row['score']??0)),'trend_score'=>min(35,absint($row['trend_score']??0)),
            'market_score'=>min(30,absint($row['market_score']??0)),'affinity_score'=>min(25,absint($row['affinity_score']??0)),'gap_score'=>min(10,absint($row['gap_score']??0)),
            'trend_value'=>absint($row['trend_value']??0),'trend_label'=>sanitize_text_field((string)($row['trend_label']??'')),
            'shopping_results'=>absint($row['shopping_results']??0),'merchant_count'=>absint($row['merchant_count']??0),'max_reviews'=>absint($row['max_reviews']??0),
            'justification'=>sanitize_textarea_field((string)($row['justification']??'')),
            'sources_json'=>wp_json_encode((array)($row['sources']??array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'evidence_json'=>wp_json_encode((array)($row['evidence']??array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'first_seen_at'=>$existing?(string)$existing['first_seen_at']:$now,'last_seen_at'=>$now,'updated_at'=>$now,
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This operation writes/migrates the module's own tables; no core object API or result cache applies.
        if($existing){$ok=$wpdb->update($table,$data,array('id'=>absint($existing['id'])));return $ok===false?new WP_Error('ojeador_novedades_update',sanitize_text_field((string)$wpdb->last_error)):absint($existing['id']);}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This operation writes/migrates the module's own tables; no core object API or result cache applies.
        $ok=$wpdb->insert($table,$data);return $ok===false?new WP_Error('ojeador_novedades_insert',sanitize_text_field((string)$wpdb->last_error)):absint($wpdb->insert_id);
    }

    private static function choose_seeds($limit,$hub_id){
        $seeds=self::seeds();
        if($hub_id)return array_values(array_filter($seeds,static function($s)use($hub_id){return absint($s['hub_primary_id']??0)===$hub_id;}));
        if(!$seeds)return array();$cursor=absint(get_option(self::OPT_CURSOR,0));if($cursor>=count($seeds))$cursor=0;$out=array();
        for($i=0;$i<$limit;$i++)$out[]=$seeds[($cursor+$i)%count($seeds)];
        update_option(self::OPT_CURSOR,($cursor+count($out))%count($seeds),false);return $out;
    }

    public static function scan($seed_limit=1,$candidate_limit=3,$hub_id=0){
        if (class_exists('SEO_Ojeador_Merchant') && SEO_Ojeador_Merchant::is_merchant_only())return new WP_Error('ojeador_merchant_only','Radar Ojeador en modo Merchant sin búsquedas de pago. Consulte Productos populares Merchant.');
        $seed_limit=max(1,min(3,absint($seed_limit)));$candidate_limit=max(1,min(5,absint($candidate_limit)));
        $seeds=self::choose_seeds($seed_limit,absint($hub_id));
        if(!$seeds)return new WP_Error('ojeador_novedades_seeds','No hay Clusters/Hubs primarios disponibles para explorar.');
        $started=microtime(true);$query_budget=8;$summary=array('started_at_utc'=>gmdate('c'),'query_budget'=>$query_budget,'seeds'=>array(),'opportunities_seen'=>0,'opportunities_saved'=>0,'api_queries'=>0,'errors'=>array());
        foreach($seeds as $seed){
            if(absint($summary['api_queries'])>=$query_budget)break;
            $report=array('cluster_name'=>$seed['cluster_name'],'hub_primary_name'=>$seed['hub_primary_name'],'candidates'=>0,'saved'=>0,'errors'=>array());
            $trace=array();$trends=SEO_Ojeador_Shopping::search_google_trends_related($seed['hub_primary_name'],'RELATED_QUERIES',$trace);$summary['api_queries']+=absint($trace['api_queries']??0);
            if(is_wp_error($trends)){$report['errors'][]=$trends->get_error_message();$summary['errors'][]=$seed['hub_primary_name'].': '.$trends->get_error_message();$summary['seeds'][]=$report;continue;}
            $candidates=self::trend_candidates((array)$trends,$candidate_limit);
            if(!$candidates){
                $trace=array();$topics=SEO_Ojeador_Shopping::search_google_trends_related($seed['hub_primary_name'],'RELATED_TOPICS',$trace);$summary['api_queries']+=absint($trace['api_queries']??0);
                if(!is_wp_error($topics))$candidates=self::trend_candidates((array)$topics,$candidate_limit);else $report['errors'][]=$topics->get_error_message();
            }
            foreach($candidates as $cand){
                if(absint($summary['api_queries'])>=$query_budget)break;
                $title=$cand['title'];if(self::norm($title)===self::norm($seed['hub_primary_name']))continue;
                $report['candidates']++;$summary['opportunities_seen']++;$coverage=self::coverage($title);

                // Search/Shopping pueden gastar hasta dos peticiones si SerpApi
                // falla y entra ScraperAPI. Reservamos ese máximo antes de cada
                // validación para respetar el presupuesto duro del radar.
                if(absint($summary['api_queries'])+2>$query_budget)break;

                $st=array();$shopping=SEO_Ojeador_Shopping::search_google_shopping($title,array(),$st);$summary['api_queries']+=absint($st['api_queries']??0);
                $market=is_wp_error($shopping)?array('count'=>0,'merchants'=>0,'max_reviews'=>0,'sources'=>array()):self::shopping_metrics((array)$shopping);

                $qt=trim($title.' '.$seed['hub_primary_name'].' profesional');
                $wt=array();$search=array('count'=>0,'sources'=>array());
                if(absint($summary['api_queries'])+2<=$query_budget){
                    $web=SEO_Ojeador_Shopping::search_google_web($qt,array('num'=>10),$wt);
                    $summary['api_queries']+=absint($wt['api_queries']??0);
                    if(!is_wp_error($web))$search=self::search_metrics((array)$web);
                }
                $ts=self::trend_score($cand['value'],$cand['label']);$ms=self::market_score($market);$as=self::affinity_score($title,$seed,$search);$gs=self::gap_score($coverage);$score=min(100,$ts+$ms+$as+$gs);$type=self::type_for($coverage,$as,$ms);
                $sources=array();if(!empty($cand['url']))$sources[]=array('type'=>'google_trends','title'=>$title.' · Google Trends','url'=>$cand['url'],'signal'=>$cand['label']);
                $sources=array_merge($sources,$market['sources'],$search['sources']);
                $just='Google Trends relaciona "'.$title.'" con el territorio "'.$seed['hub_primary_name'].'"'.($cand['label']!==''?' ('.$cand['label'].')':'').'. ';
                if($coverage['status']==='absent')$just.='No se encontró una categoría WooCommerce equivalente con el contraste actual. ';
                elseif($coverage['status']==='partial')$just.='Existe cobertura parcial próxima en "'.$coverage['name'].'". ';
                else $just.='La familia ya está cubierta en "'.$coverage['name'].'"; la oportunidad es de producto o subfamilia. ';
                $just.='Shopping devuelve '.absint($market['count']).' resultados y '.absint($market['merchants']).' comercios; score '.$score.'/100.';
                $saved=self::upsert(array(
                    'title'=>$title,'opportunity_type'=>$type,'coverage_status'=>$coverage['status'],'matched_term_id'=>$coverage['term_id'],'matched_category'=>$coverage['name'],
                    'cluster_id'=>$seed['cluster_id'],'cluster_name'=>$seed['cluster_name'],'hub_primary_id'=>$seed['hub_primary_id'],'hub_primary_name'=>$seed['hub_primary_name'],
                    'score'=>$score,'trend_score'=>$ts,'market_score'=>$ms,'affinity_score'=>$as,'gap_score'=>$gs,'trend_value'=>$cand['value'],'trend_label'=>$cand['label'],
                    'shopping_results'=>$market['count'],'merchant_count'=>$market['merchants'],'max_reviews'=>$market['max_reviews'],'justification'=>$just,'sources'=>$sources,
                    'evidence'=>array('trend_kind'=>$cand['kind'],'coverage'=>$coverage,'shopping'=>$market,'search'=>$search,'score_components'=>array('trend'=>$ts,'market'=>$ms,'affinity'=>$as,'catalog_gap'=>$gs),'provider_trace'=>array('shopping'=>$st,'search'=>$wt))
                ));
                if(is_wp_error($saved)){$report['errors'][]=$title.': '.$saved->get_error_message();$summary['errors'][]=$title.': '.$saved->get_error_message();continue;}
                $report['saved']++;$summary['opportunities_saved']++;
            }
            $summary['seeds'][]=$report;
        }
        $summary['duration_ms']=max(0,(int)round((microtime(true)-$started)*1000));$summary['completed_at_utc']=gmdate('c');update_option(self::OPT_LAST,$summary,false);return $summary;
    }

    public static function handle_scan(){
        self::guard('seo_ojeador_novedades_scan');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability verified by self::guard() above.
        $r=self::scan(absint(wp_unslash($_POST['seed_limit']??1)),absint(wp_unslash($_POST['candidate_limit']??3)),absint(wp_unslash($_POST['hub_primary_id']??0)));
        if(is_wp_error($r))self::redirect(array('ojeador_error'=>rawurlencode($r->get_error_message())));
        self::redirect(array('ojeador_notice'=>'novedades_scanned','novedades_saved'=>absint($r['opportunities_saved']??0),'novedades_queries'=>absint($r['api_queries']??0)));
    }
    public static function handle_status(){
        self::guard('seo_ojeador_novedades_status');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability verified by self::guard() above.
        global $wpdb;$id=absint(wp_unslash($_POST['opportunity_id']??0));$status=sanitize_key((string)wp_unslash($_POST['opportunity_status']??'detected'));
        if(!in_array($status,array('detected','investigate','watch','dismissed'),true))$status='detected';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This operation writes/migrates the module's own tables; no core object API or result cache applies.
        if($id)$wpdb->update(self::table(),array('status'=>$status,'updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$id));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        self::redirect(array('ojeador_notice'=>'novedades_status'));
    }
    public static function handle_export(){
        self::guard('seo_ojeador_novedades_export');$rows=self::rows(array('limit'=>2000));
        while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="ojeador-novedades-'.gmdate('Ymd-His').'.json"');header('X-Content-Type-Options: nosniff');
        echo wp_json_encode(array('meta'=>array('schema'=>'seo_ojeador_novedades','schema_version'=>'1.0.0','generated_at_utc'=>gmdate('c'),'site_url'=>home_url('/'),'ojeador_version'=>defined('SEO_OJEADOR_VERSION')?SEO_OJEADOR_VERSION:''),'strategy'=>array('boundary'=>'cluster + hub_primary','discovery'=>'Google Trends rising related queries/topics','validation'=>'Google Shopping + Google Search','catalog_policy'=>'propuesta humana; no crea categorías ni productos'),'last_run'=>get_option(self::OPT_LAST,array()),'seeds'=>self::seeds(),'opportunities'=>$rows),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);exit;
    }

    public static function rows($args=array()){
        global $wpdb;$a=wp_parse_args($args,array('status'=>'','type'=>'','min_score'=>0,'q'=>'','limit'=>500));$table=self::table();$limit=max(1,min(2000,absint($a['limit'])));
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table, limit prepared.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live read of custom research/catalog tables; writes and cron modify these rows and there is no safe shared cache invalidation.
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY score DESC,last_seen_at DESC LIMIT %d", $table,$limit),ARRAY_A);
        $status=sanitize_key((string)$a['status']);$type=sanitize_key((string)$a['type']);$min=absint($a['min_score']);$q=self::norm($a['q']);$out=array();
        foreach($rows as $r){
            if($status!==''&&$r['status']!==$status)continue;if($type!==''&&$r['opportunity_type']!==$type)continue;if(absint($r['score'])<$min)continue;
            if($q!==''&&strpos(self::norm($r['title'].' '.$r['cluster_name'].' '.$r['hub_primary_name'].' '.$r['matched_category']),$q)===false)continue;
            $r['sources']=json_decode((string)$r['sources_json'],true);if(!is_array($r['sources']))$r['sources']=array();$out[]=$r;
        }return $out;
    }
    public static function dashboard($args=array()){
        $rows=self::rows($args);$all=self::rows(array('limit'=>2000));$stats=array('total'=>count($all),'new_category'=>0,'catalog_gap'=>0,'trending_product'=>0,'strategic_expansion'=>0,'high_score'=>0);
        foreach($all as $r){$t=$r['opportunity_type'];if(isset($stats[$t]))$stats[$t]++;if(absint($r['score'])>=70)$stats['high_score']++;}
        return array('rows'=>$rows,'stats'=>$stats,'last_run'=>get_option(self::OPT_LAST,array()),'seeds'=>self::seeds());
    }

    public static function render($d){
        $rows=(array)($d['rows']??array());$stats=(array)($d['stats']??array());$seeds=(array)($d['seeds']??array());$last=(array)($d['last_run']??array());
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtros GET de consulta: no alteran estado ni inician procesos.
        $fs=sanitize_key((string)wp_unslash($_GET['nstatus']??''));$ft=sanitize_key((string)wp_unslash($_GET['ntype']??''));$fm=absint(wp_unslash($_GET['nscore']??0));$fq=sanitize_text_field(wp_unslash($_GET['nq']??''));
        ?>
        <section style="margin-top:18px">
            <div class="seo-ojeador-box">
                <h2 style="margin-top:0">Novedades · radar de mercado</h2>
                <p class="seo-ojeador-muted">Explora fuera de las categorías actuales sin salir del perímetro de <strong>Clusters + Hubs primarios</strong>. Trends descubre señales crecientes; Shopping y Search validan mercado y fuentes. No crea categorías ni productos automáticamente.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seo_ojeador_novedades_scan'); ?><input type="hidden" name="action" value="seo_ojeador_novedades_scan">
                    <div class="seo-ojeador-filter-grid">
                        <label><strong>Territorio</strong><select name="hub_primary_id"><option value="0">Siguiente Hub primario (rotación)</option><?php foreach($seeds as $s):?><option value="<?php echo esc_attr((string)$s['hub_primary_id']); ?>"><?php echo esc_html($s['cluster_name'].' → '.$s['hub_primary_name']); ?></option><?php endforeach;?></select></label>
                        <label><strong>Hubs por ejecución</strong><select name="seed_limit"><?php foreach(array(1,2,3) as $n):?><option value="<?php echo esc_attr((string)$n); ?>"><?php echo esc_html((string)$n); ?></option><?php endforeach;?></select></label>
                        <label><strong>Candidatos por Hub</strong><select name="candidate_limit"><?php foreach(array(2,3,5) as $n):?><option value="<?php echo esc_attr((string)$n); ?>" <?php selected($n,2); ?>><?php echo esc_html((string)$n); ?></option><?php endforeach;?></select></label>
                    </div>
                    <p style="margin-bottom:0"><button class="button button-primary">Buscar novedades</button> <span class="seo-ojeador-muted">Presupuesto: aprox. 1 Trends + 2 validaciones por candidato, con tope duro de 8 peticiones por ejecución.</span></p>
                </form>
                <?php if($last):?><p class="seo-ojeador-run"><strong>Último radar:</strong> <?php echo esc_html(number_format_i18n(absint($last['opportunities_saved']??0))); ?> oportunidades · <?php echo esc_html(number_format_i18n(absint($last['api_queries']??0))); ?> peticiones · <?php echo esc_html((string)($last['completed_at_utc']??'')); ?> UTC</p><?php endif;?>
            </div>

            <div class="seo-ojeador-cards">
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n(absint($stats['total']??0))); ?></strong><span>Oportunidades</span></div>
                <div class="seo-ojeador-card seo-ojeador-kpi-action"><strong><?php echo esc_html(number_format_i18n(absint($stats['new_category']??0))); ?></strong><span>Nuevas categorías</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n(absint($stats['catalog_gap']??0))); ?></strong><span>Huecos catálogo</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n(absint($stats['trending_product']??0))); ?></strong><span>Productos/tecnologías</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n(absint($stats['strategic_expansion']??0))); ?></strong><span>Expansión</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n(absint($stats['high_score']??0))); ?></strong><span>Score ≥ 70</span></div>
            </div>

            <div class="seo-ojeador-box" style="margin-bottom:14px"><form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"><input type="hidden" name="page" value="seo-ojeador"><input type="hidden" name="ojeador_tab" value="novedades">
                <div class="seo-ojeador-filter-grid">
                    <label><strong>Buscar</strong><input type="search" name="nq" value="<?php echo esc_attr($fq); ?>"></label>
                    <label><strong>Estado</strong><select name="nstatus"><option value="">Todos</option><?php foreach(array('detected'=>'Detectada','investigate'=>'Investigar','watch'=>'Vigilar','dismissed'=>'Descartada') as $k=>$l):?><option value="<?php echo esc_attr($k); ?>" <?php selected($fs,$k); ?>><?php echo esc_html($l); ?></option><?php endforeach;?></select></label>
                    <label><strong>Tipo</strong><select name="ntype"><option value="">Todos</option><?php foreach(array('new_category','catalog_gap','trending_product','strategic_expansion') as $k):?><option value="<?php echo esc_attr($k); ?>" <?php selected($ft,$k); ?>><?php echo esc_html(self::type_label($k)); ?></option><?php endforeach;?></select></label>
                    <label><strong>Score mínimo</strong><input type="number" min="0" max="100" name="nscore" value="<?php echo esc_attr((string)$fm); ?>"></label>
                </div><p style="margin-bottom:0"><button class="button">Aplicar filtros</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seo-ojeador&ojeador_tab=novedades')); ?>">Limpiar</a></p>
            </form></div>

            <div class="seo-ojeador-table"><table class="widefat striped"><thead><tr><th>Oportunidad</th><th>Territorio</th><th>Tipo / cobertura</th><th>Score</th><th>Mercado</th><th>Justificación y fuentes</th><th>Estado</th></tr></thead><tbody>
            <?php foreach($rows as $r):$sources=(array)($r['sources']??array());$status=sanitize_key((string)$r['status']);?>
                <tr>
                    <td><strong><?php echo esc_html($r['title']); ?></strong><br><small class="seo-ojeador-muted"><?php echo esc_html($r['first_seen_at']); ?></small></td>
                    <td><strong><?php echo esc_html($r['cluster_name']); ?></strong><br><?php echo esc_html($r['hub_primary_name']); ?></td>
                    <td><strong><?php echo esc_html(self::type_label($r['opportunity_type'])); ?></strong><br><small><?php echo esc_html(self::coverage_label($r['coverage_status'])); ?><?php if($r['matched_category']!==''):?> · <?php echo esc_html($r['matched_category']); ?><?php endif;?></small></td>
                    <td><strong style="font-size:20px"><?php echo esc_html((string)absint($r['score'])); ?>/100</strong><br><small>T <?php echo esc_html((string)absint($r['trend_score'])); ?>/35 · M <?php echo esc_html((string)absint($r['market_score'])); ?>/30 · A <?php echo esc_html((string)absint($r['affinity_score'])); ?>/25 · H <?php echo esc_html((string)absint($r['gap_score'])); ?>/10</small></td>
                    <td><strong><?php echo esc_html($r['trend_label']?:'—'); ?></strong> Trends<br><small><?php echo esc_html(number_format_i18n(absint($r['shopping_results']))); ?> resultados · <?php echo esc_html(number_format_i18n(absint($r['merchant_count']))); ?> comercios · máx. <?php echo esc_html(number_format_i18n(absint($r['max_reviews']))); ?> reviews</small></td>
                    <td style="min-width:330px"><?php echo esc_html($r['justification']); ?><?php if($sources):?><details style="margin-top:6px"><summary>Fuentes (<?php echo esc_html((string)count($sources)); ?>)</summary><ul><?php foreach(array_slice($sources,0,8) as $src):$u=esc_url((string)($src['url']??''));if($u==='')continue;?><li><a href="<?php echo esc_url($u); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($src['title']??$src['type']??'Fuente')); ?></a> <small><?php echo esc_html((string)($src['type']??'')); ?></small></li><?php endforeach;?></ul></details><?php endif;?></td>
                    <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_novedades_status'); ?><input type="hidden" name="action" value="seo_ojeador_novedades_status"><input type="hidden" name="opportunity_id" value="<?php echo esc_attr((string)absint($r['id'])); ?>"><select name="opportunity_status"><?php foreach(array('detected'=>'Detectada','investigate'=>'Investigar','watch'=>'Vigilar','dismissed'=>'Descartada') as $k=>$l):?><option value="<?php echo esc_attr($k); ?>" <?php selected($status,$k); ?>><?php echo esc_html($l); ?></option><?php endforeach;?></select><br><button class="button" style="margin-top:5px">Guardar</button></form></td>
                </tr>
            <?php endforeach;?><?php if(!$rows):?><tr><td colspan="7">Aún no hay oportunidades. Ejecuta «Buscar novedades».</td></tr><?php endif;?>
            </tbody></table></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px"><?php wp_nonce_field('seo_ojeador_novedades_export'); ?><input type="hidden" name="action" value="seo_ojeador_novedades_export"><button class="button">Exportar JSON Novedades</button></form>
        </section>
        <?php
    }
}
