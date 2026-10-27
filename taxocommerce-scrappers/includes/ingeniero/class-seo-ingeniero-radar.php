<?php
/** Independent external trend radar. No category or WordPress content is created automatically. */
defined('ABSPATH') || exit;
final class SEO_Ingeniero_Radar {
    const OPTION='seo_ingeniero_external_radar_v1';
    const HOOK='seo_ingeniero_external_radar_tick';
    public static function init() {
        add_action(self::HOOK,array(__CLASS__,'scan'));
        add_action('admin_post_seo_ingeniero_radar_scan',array(__CLASS__,'handle_scan'));
        add_action('admin_post_seo_ingeniero_radar_review',array(__CLASS__,'handle_review'));
        add_action('init',array(__CLASS__,'schedule'),25);
    }
    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time()+HOUR_IN_SECONDS,'daily',self::HOOK);
    }
    public static function state() {
        $state=get_option(self::OPTION,array());
        return is_array($state)?$state:array();
    }
    public static function seeds() {
        $signals=class_exists('SEO_Editorial_Opportunities')?SEO_Editorial_Opportunities::research_seeds(5):array();
        $cats=class_exists('SEO_Ingeniero')?SEO_Ingeniero::category_candidates(10,false):array();
        $counts=class_exists('SEO_Editorial_Opportunities')?SEO_Editorial_Opportunities::category_demand_priorities():array();
        usort($cats,static function($a,$b)use($counts){
            return ($counts[absint($b['term_id']??0)]??0)<=>($counts[absint($a['term_id']??0)]??0);
        });
        foreach (array_slice($cats,0,4) as $cat) {
            $label=sanitize_text_field((string)($cat['name']??''));
            if ($label==='') continue;
            $signals[]=array('query'=>$label,'source'=>'Catálogo / sectores relacionados',
                'priority'=>absint($counts[absint($cat['term_id']??0)]??0),
                'category_ids'=>array(absint($cat['term_id']??0)), 'signal_id'=>'cat:'.absint($cat['term_id']??0));
        }
        return $signals;
    }
    public static function scan($manual=false) {
        if (get_transient('seo_ingeniero_radar_lock_v1')) return new WP_Error('ingeniero_radar_busy','Radar en ejecución.');
        set_transient('seo_ingeniero_radar_lock_v1',1,5*MINUTE_IN_SECONDS);
        try {
            $state=self::state();$all=(array)($state['items']??array());
            $stats=array('searched'=>0,'found'=>0,'errors'=>0,'at'=>current_time('mysql'));
            $provider=class_exists('SEO_Ingeniero')?SEO_Ingeniero::provider():new WP_Error('radar_provider','Ingeniero no disponible.');
            if (is_wp_error($provider)) return $provider;
            $seeds=self::seeds();
            if (!$seeds) { $state['last_run']=$stats;update_option(self::OPTION,$state,false);return $stats; }
            $cursor=absint($state['cursor']??0)%count($seeds);
            $budget=$manual?2:1; // Conservative shared SerpApi / ScraperAPI quotas.
            for($i=0;$i<$budget;$i++) {
                $seed=$seeds[($cursor+$i)%count($seeds)];
                $query=trim((string)($seed['query']??''));
                if ($query==='') continue;
                $search=$provider->search($query.' innovaciones novedades herramientas aplicaciones '.gmdate('Y'),
                    array('lesson'=>SEO_Ingeniero::LESSON_CURRENT));
                $stats['searched']++;
                if (is_wp_error($search)) {$stats['errors']++;$state['last_error']=$search->get_error_message();continue;}
                foreach(array_slice((array)($search['results']??array()),0,8)as$row) {
                    $url=esc_url_raw((string)($row['url']??''));
                    if (wp_parse_url($url,PHP_URL_SCHEME)!=='https' || !wp_http_validate_url($url)) continue;
                    $title=sanitize_text_field((string)($row['title']??''));
                    if (mb_strlen($title)<12) continue;
                    $key=hash('sha256',strtolower(untrailingslashit($url)));
                    $old=(array)($all[$key]??array());
                    $status=sanitize_key((string)($old['status']??'research'));
                    $type=self::suggest_type($title,(string)($row['snippet']??''),$seed);
                    $item=array(
                        'id'=>$key,'title'=>$title,'url'=>$url,
                        'domain'=>sanitize_text_field((string)wp_parse_url($url,PHP_URL_HOST)),
                        'snippet'=>sanitize_textarea_field((string)($row['snippet']??'')),
                        'published_at'=>sanitize_text_field((string)($row['date']??'')),
                        'retrieved_at'=>current_time('mysql'),
                        'seed'=>$query,'seed_source'=>sanitize_text_field((string)($seed['source']??'')),
                        'signal_id'=>sanitize_text_field((string)($seed['signal_id']??'')),
                        'priority'=>absint($seed['priority']??0),
                        'suggested_type'=>$type,'status'=>$status,
                        'category_ids'=>array_values(array_unique(array_filter(array_map('absint',(array)($seed['category_ids']??array()))))),
                        'note'=>(string)($old['note']??''),
                        'reviewed_by'=>absint($old['reviewed_by']??0),
                        'reviewed_at'=>(string)($old['reviewed_at']??''),
                    );
                    if (!isset($all[$key]))$stats['found']++;
                    // Retain the human decision and notes.
                    $all[$key]=$item;
                }
            }
            $state['cursor']=($cursor+$budget)%count($seeds);
            $state['last_run']=$stats;
            // Bounded persisted storage, keeping reviewed items and most recent first.
            uasort($all,static function($a,$b){return strcmp((string)($b['retrieved_at']??''),(string)($a['retrieved_at']??''));});
            $state['items']=array_slice($all,0,250,true);
            update_option(self::OPTION,$state,false);
            return $stats;
        } finally {
            delete_transient('seo_ingeniero_radar_lock_v1');
        }
    }
    public static function suggest_type($title,$summary,array$seed) {
        $s=remove_accents(mb_strtolower($title.' '.$summary));
        if (preg_match('/lanzamiento|presenta|anuncia|nueva gama|nueva generacion|feria|actualidad|noticia/i',$s))return 'news';
        if (preg_match('/comparativa|guia|como elegir|consejos|mantenimiento|seguridad/i',$s))return 'post';
        return 'investigate'; // No invented landing/category from a snippet.
    }
    public static function handle_scan() {
        if (!current_user_can('manage_options'))wp_die('Permisos insuficientes.');
        check_admin_referer('seo_ingeniero_radar_scan');
        $result=self::scan(true);
        wp_safe_redirect(add_query_arg(array('page'=>'seo-ingeniero','tab'=>'radar',
            'radar_notice'=>is_wp_error($result)?'error':'scanned'),admin_url('admin.php')));exit;
    }
    public static function handle_review() {
        if (!current_user_can('manage_options'))wp_die('Permisos insuficientes.');
        check_admin_referer('seo_ingeniero_radar_review');
        $key=sanitize_text_field(wp_unslash($_POST['item_id']??''));
        $decision=sanitize_key(wp_unslash($_POST['decision']??'research'));
        if (!preg_match('/^[0-9a-f]{64}$/',$key)||!in_array($decision,array('research','post','news','landing','category','rejected'),true))wp_die('Decisión no válida.');
        $state=self::state();
        if (empty($state['items'][$key]))wp_die('La oportunidad ya no existe.');
        $state['items'][$key]['status']=$decision;
        $state['items'][$key]['note']=sanitize_textarea_field(wp_unslash($_POST['note']??''));
        $state['items'][$key]['reviewed_by']=get_current_user_id();
        $state['items'][$key]['reviewed_at']=current_time('mysql');
        update_option(self::OPTION,$state,false);
        wp_safe_redirect(add_query_arg(array('page'=>'seo-ingeniero','tab'=>'radar','radar_notice'=>'saved'),admin_url('admin.php')));exit;
    }
    public static function render() {
        if (!current_user_can('manage_options'))return;
        $state=self::state();$stats=(array)($state['last_run']??array());
        echo '<h2>Radar externo · novedades, tendencias y nuevos ámbitos</h2>';
        echo '<p>Ingeniero consulta noticias externas conectadas a demandas de Analista y familias del catálogo. El hallazgo es una pista de investigación, nunca un hecho técnico verificado ni contenido listo para publicar. Solucionador no consulta internet.</p>';
        echo '<p><strong>Última ejecución:</strong> '.esc_html((string)($stats['at']??'Nunca')).' · <strong>Búsquedas:</strong> '.esc_html((string)($stats['searched']??0)).' · <strong>Nuevos hallazgos:</strong> '.esc_html((string)($stats['found']??0)).' · <strong>Errores:</strong> '.esc_html((string)($stats['errors']??0)).'</p>';
        if (!empty($state['last_error']))echo '<p class="notice notice-warning">'.esc_html((string)$state['last_error']).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="seo_ingeniero_radar_scan">';
        wp_nonce_field('seo_ingeniero_radar_scan');submit_button('Buscar novedades ahora (máx. 2 consultas)','secondary','submit',false);echo '</form>';
        echo '<p class="description">Noticias, ideas de posts, posibles landings y categorías nuevas quedan separadas como recomendaciones. Solo los editores decidirán qué desarrollar; no se crean objetos WordPress automáticamente.</p>';
        $items=(array)($state['items']??array());
        if (!$items)echo '<p>Sin hallazgos todavía. Ejecuta una exploración cuando haya crédito de proveedores.</p>';
        foreach(array_slice($items,0,80,true)as$key=>$item) {
            echo '<details class="postbox" style="padding:12px;margin:10px 0"><summary><strong>'.esc_html((string)$item['title']).'</strong> · '.esc_html((string)$item['status']).' · '.esc_html((string)$item['seed_source']).' · prioridad '.esc_html((string)$item['priority']).'</summary>';
            echo '<p><a href="'.esc_url((string)$item['url']).'" target="_blank" rel="noopener noreferrer">Fuente original</a> · '.esc_html((string)$item['published_at']).' · '.esc_html((string)$item['domain']).'</p>';
            echo '<p>'.esc_html((string)$item['snippet']).'</p>';
            echo '<p><strong>Consulta:</strong> '.esc_html((string)$item['seed']).' · <strong>Clasificación sugerida:</strong> '.esc_html((string)$item['suggested_type']).' · <strong>Categorías verificadas:</strong> '.esc_html(implode(', ',(array)$item['category_ids'])).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="seo_ingeniero_radar_review"><input type="hidden" name="item_id" value="'.esc_attr($key).'">';
            wp_nonce_field('seo_ingeniero_radar_review');
            echo '<label>Destino editorial <select name="decision">';
            foreach(array('research'=>'Investigar','news'=>'Noticia','post'=>'Post','landing'=>'Posible landing','category'=>'Posible categoría nueva','rejected'=>'Descartar')as$v=>$label)echo '<option value="'.esc_attr($v).'" '.selected((string)$item['status'],$v,false).'>'.esc_html($label).'</option>';
            echo '</select></label>';
            echo '<p><label>Justificación / comprobaciones pendientes<br><textarea name="note" rows="2" style="width:100%">'.esc_textarea((string)$item['note']).'</textarea></label></p>';
            submit_button('Guardar decisión editorial','secondary','submit',false);echo '</form></details>';
        }
    }
}
