<?php
defined('ABSPATH') || exit;

/**
 * Dependiente learning engine.
 *
 * Keeps the ten lessons but separates two auditable phases:
 * 1) freeze a lesson snapshot from the current catalogue;
 * 2) learn only from that immutable snapshot.
 *
 * The historical class name is kept for API compatibility with the first
 * standalone build. It is not exposed as a separate "Academia" service.
 */
final class TCD_Academy {
    const CRON_HOOK='tcd_learning_tick';
    const STATE='tcd_learning_state';
    const LOG='tcd_learning_log';
    const LOCK='tcd_learning_tick_lock';
    const SCHEMA_OPTION='tcd_learning_schema_version';
    const SCHEMA_VERSION='2.0.0';

    public static function knowledge_table(){global $wpdb;return $wpdb->prefix.'tcd_knowledge';}
    public static function snapshots_table(){global $wpdb;return $wpdb->prefix.'tcd_lesson_snapshots';}
    public static function items_table(){global $wpdb;return $wpdb->prefix.'tcd_lesson_items';}

    public static function install(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $knowledge=self::knowledge_table();
        $snapshots=self::snapshots_table();
        $items=self::items_table();

        dbDelta("CREATE TABLE {$knowledge} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lesson smallint(5) unsigned NOT NULL,
            source_type varchar(50) NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            signature char(64) NOT NULL,
            title text NOT NULL,
            context_json longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'learned',
            reason text NULL,
            learned_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_source (lesson,source_type,source_id),
            KEY lesson_status (lesson,status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$snapshots} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id char(36) NOT NULL,
            lesson smallint(5) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'building',
            source_count bigint(20) unsigned NOT NULL DEFAULT 0,
            learned_count bigint(20) unsigned NOT NULL DEFAULT 0,
            unlearned_count bigint(20) unsigned NOT NULL DEFAULT 0,
            source_signature char(64) NULL,
            syllabus_json longtext NULL,
            created_at datetime NOT NULL,
            sealed_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_lesson (run_id,lesson),
            KEY lesson_status (lesson,status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_id bigint(20) unsigned NOT NULL,
            lesson smallint(5) unsigned NOT NULL,
            sequence_no bigint(20) unsigned NOT NULL DEFAULT 0,
            source_type varchar(50) NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            source_key varchar(191) NULL,
            signature char(64) NOT NULL,
            title text NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            reason text NULL,
            learned_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY snapshot_source (snapshot_id,source_type,source_id),
            KEY snapshot_status (snapshot_id,status,id),
            KEY lesson_status (lesson,status)
        ) {$charset};");

        if(false===get_option(self::STATE,false)){
            add_option(self::STATE,self::initial_state(),'',false);
        }else{
            self::save(self::state());
        }
        update_option(self::SCHEMA_OPTION,self::SCHEMA_VERSION,false);
    }

    private static function maybe_upgrade(){
        if(self::SCHEMA_VERSION!==(string)get_option(self::SCHEMA_OPTION,''))self::install();
    }

    private static function initial_state(){
        return array(
            'status'=>'idle',
            'phase'=>'snapshot',
            'lesson'=>1,
            'snapshot_id'=>0,
            'snapshot_cursor'=>0,
            'learning_cursor'=>0,
            'processed'=>0,
            'learned'=>0,
            'unlearned'=>0,
            'run_id'=>'',
            'last_error'=>'',
            'last_message'=>'Preparado para analizar y aprender L1-L10.',
            'updated_at'=>'',
            'load_last'=>array(),
        );
    }

    public static function state(){
        $raw=get_option(self::STATE,array());
        // Migrate the 0.1.x state without destroying its knowledge table.
        $state=wp_parse_args(is_array($raw)?$raw:array(),self::initial_state());
        if(empty($state['phase']))$state['phase']='snapshot';
        return $state;
    }

    public static function save($changes){
        $state=self::state();
        $stop=get_option('tcd_learning_stop','');
        if($stop && $stop===($state['run_id']??'') && 'running'===($changes['status']??''))return $state;
        $state=array_merge($state,(array)$changes);
        if($stop && $stop===($state['run_id']??''))$state['status']='stopped';
        $state['updated_at']=current_time('mysql');
        update_option(self::STATE,$state,false);
        // Delete obsolete 0.1.x stop marker once new state is active.
        delete_option('tcd_academy_stop');
        return $state;
    }

    public static function log($event,$context=array()){
        $rows=(array)get_option(self::LOG,array());
        $clean=array();
        foreach((array)$context as $key=>$value){
            $clean[sanitize_key((string)$key)]=is_scalar($value)?sanitize_text_field((string)$value):wp_json_encode($value);
        }
        $rows[]=array('at'=>gmdate('c'),'event'=>sanitize_key($event),'context'=>$clean);
        update_option(self::LOG,array_slice($rows,-150),false);
    }

    public static function logs(){return (array)get_option(self::LOG,array());}

    public static function boot(){
        self::maybe_upgrade();
        add_action(self::CRON_HOOK,array(__CLASS__,'tick'));
        add_filter('seo_process_supervisor_has_pending_work',array(__CLASS__,'filter_pending_work'),40,1);
        add_filter('seo_process_supervisor_manager_targets',array(__CLASS__,'filter_manager_targets'),40,3);
        add_action('admin_post_tcd_academy_control',array(__CLASS__,'control'));
        add_action('admin_post_tcd_dependiente_export_json',array(__CLASS__,'export_json'));
    }

    public static function control(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Permisos insuficientes.');
        check_admin_referer('tcd_academy_control');
        $action=isset($_POST['tcd_action'])?sanitize_key(wp_unslash($_POST['tcd_action'])):'';
        $state=self::state();

        if('priority'===$action){
            $priority=isset($_POST['priority'])?wp_unslash($_POST['priority']):'';
            $updated=TCD_Load::set_priority($priority);
            if(is_wp_error($updated))self::log('priority_invalid',array('error'=>$updated->get_error_message()));
            else self::log('priority_saved',array('priority'=>$updated,'label'=>TCD_Load::label($updated)));
        }elseif('stop'===$action){
            update_option('tcd_learning_stop',(string)$state['run_id'],false);
            self::save(array('status'=>'stopped','last_message'=>'Detenido: no se iniciarán más lotes; se conserva la foto fija y el progreso.'));
            wp_clear_scheduled_hook(self::CRON_HOOK);
            self::log('stopped',array('lesson'=>$state['lesson'],'phase'=>$state['phase']));
        }elseif('start'===$action && in_array($state['status'],array('idle','stopped','error'),true)){
            $run=(string)($state['run_id']??'');
            if(!$run){
                self::clear_learning_tables();
                $run=wp_generate_uuid4();
                $state=self::initial_state();
                self::save($state);
            }
            delete_option('tcd_learning_stop');
            self::save(array('run_id'=>$run,'status'=>'running','last_error'=>'','last_message'=>'Dependiente iniciado. Primero analizará la lección y cerrará su foto fija.'));
            self::queue();
            self::nudge_manager();
            self::log('started',array('lesson'=>$state['lesson'],'phase'=>$state['phase'],'run'=>$run));
        }elseif('restart'===$action && in_array($state['status'],array('idle','stopped','error','completed'),true)){
            self::clear_learning_tables();
            delete_option('tcd_learning_stop');
            self::save(array_merge(self::initial_state(),array('status'=>'running','run_id'=>wp_generate_uuid4())));
            self::queue();
            self::nudge_manager();
            self::log('restarted');
        }
        wp_safe_redirect(admin_url('admin.php?page=taxocommerce-dependiente'));
        exit;
    }

    private static function clear_learning_tables(){
        global $wpdb;
        foreach(array(self::knowledge_table(),self::items_table(),self::snapshots_table()) as $table){
            // Internal plugin tables. This never touches WooCommerce/WordPress source content.
            $wpdb->query("DELETE FROM {$table}");
        }
    }

    private static function queue($delay=null){
        if('running'!==self::state()['status'])return;
        $delay=null===$delay ? TCD_Load::delay() : max(1,absint($delay));
        if(!wp_next_scheduled(self::CRON_HOOK))wp_schedule_single_event(time()+$delay,self::CRON_HOOK);
        self::nudge_manager($delay);
    }

    private static function nudge_manager($delay=0){
        if(function_exists('seo_process_supervisor_nudge')){
            seo_process_supervisor_nudge(max(0,absint($delay)),'dependiente');
        }
    }

    public static function filter_pending_work($pending){
        return $pending || 'running'===(string)(self::state()['status']??'');
    }

    public static function filter_manager_targets($targets,$settings,$source){
        if('running'!==(string)(self::state()['status']??''))return $targets;
        $targets[]=array(
            'type'=>'dependiente',
            'data'=>array(),
            'callback'=>array(__CLASS__,'manager_target_callback'),
        );
        return $targets;
    }

    public static function manager_target_callback($budget,$source,$target){
        $started=microtime(true);
        $budget=max(3,min(15,absint($budget)));
        $worked=false;
        do{
            if('running'!==(string)(self::state()['status']??''))break;
            self::tick();
            $worked=true;
            if((microtime(true)-$started)>=max(1,$budget-1))break;
        }while(false);
        return $worked;
    }

    public static function tick(){
        $state=self::state();
        if('running'!==$state['status'] || get_option('tcd_learning_stop','')===$state['run_id'])return;
        $lock=get_option(self::LOCK,0);
        if($lock && (time()-absint($lock))<120){self::queue(15);return;}
        if(!add_option(self::LOCK,time(),'',false)){
            if($lock && time()-absint($lock)>=120){delete_option(self::LOCK);self::queue(3);}
            else self::queue(15);
            return;
        }
        try{self::run_one($state);}finally{delete_option(self::LOCK);}
    }

    private static function run_one($state){
        $lesson=absint($state['lesson']);
        if($lesson>10){
            self::save(array('status'=>'completed','last_message'=>'Las diez lecciones han terminado y el conocimiento es exportable.'));
            return;
        }
        $measure=TCD_Load::begin();
        $processed=0;
        try{
            if('snapshot'===(string)$state['phase']){
                $processed=self::build_snapshot($state);
            }else{
                $processed=self::learn_snapshot($state);
            }
            $load=TCD_Load::finish($measure,$processed);
            self::save(array('load_last'=>$load));
            self::log('load_sample',array(
                'priority'=>$load['priority']??0,
                'batch'=>$load['batch']??0,
                'next_batch'=>$load['next_batch']??0,
                'cpu_percent'=>null===$load['cpu_percent']?'n/a':$load['cpu_percent'],
                'elapsed'=>$load['elapsed']??0,
                'memory_percent'=>null===$load['memory_percent']?'n/a':$load['memory_percent'],
            ));
        }catch(Throwable $e){
            $load=TCD_Load::finish($measure,$processed);
            self::save(array('status'=>'error','load_last'=>$load,'last_error'=>substr($e->getMessage(),0,400),'last_message'=>'Dependiente detenido por error. La foto fija y el progreso se conservan.'));
            self::log('learning_error',array('lesson'=>$lesson,'phase'=>$state['phase'],'error'=>substr($e->getMessage(),0,240)));
            error_log('[TaxoCommerce Dependiente] '.substr($e->getMessage(),0,400));
        }
    }

    private static function ensure_snapshot($state){
        global $wpdb;
        $id=absint($state['snapshot_id']??0);
        if($id)return $id;
        $syllabus=TCD_Sources::syllabus();
        $lesson=absint($state['lesson']);
        $definition=(array)($syllabus[$lesson]??array());
        $row=array(
            'run_id'=>(string)$state['run_id'],
            'lesson'=>$lesson,
            'status'=>'building',
            'source_count'=>0,
            'learned_count'=>0,
            'unlearned_count'=>0,
            'syllabus_json'=>wp_json_encode($definition,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>current_time('mysql'),
        );
        if(false===$wpdb->insert(self::snapshots_table(),$row))throw new RuntimeException('No se pudo crear la foto fija de L'.$lesson.'.');
        $id=(int)$wpdb->insert_id;
        self::save(array('snapshot_id'=>$id,'snapshot_cursor'=>0,'learning_cursor'=>0,'last_message'=>'L'.$lesson.': analizando fuentes para construir la foto fija.'));
        self::log('snapshot_started',array('lesson'=>$lesson,'snapshot_id'=>$id));
        return $id;
    }

    private static function build_snapshot($state){
        global $wpdb;
        $lesson=absint($state['lesson']);
        $run=(string)$state['run_id'];
        if(get_option('tcd_learning_stop','')===$run)return 0;
        $snapshot_id=self::ensure_snapshot($state);
        $state=self::state();
        $cursor=absint($state['snapshot_cursor']);
        $batch=TCD_Sources::batch($lesson,$cursor,TCD_Load::batch_size());
        if(is_wp_error($batch))throw new RuntimeException($batch->get_error_message());

        $sequence=$cursor;
        foreach((array)$batch['items'] as $item){
            if(get_option('tcd_learning_stop','')===$run)return;
            $sequence++;
            $payload=wp_json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $row=array(
                'snapshot_id'=>$snapshot_id,
                'lesson'=>$lesson,
                'sequence_no'=>$sequence,
                'source_type'=>sanitize_key((string)($item['source_type']??'')),
                'source_id'=>absint($item['source_id']??0),
                'source_key'=>sanitize_text_field((string)($item['source_key']??(($item['source_type']??'source').':'.absint($item['source_id']??0)))),
                'signature'=>sanitize_text_field((string)($item['signature']??hash('sha256',$payload))),
                'title'=>sanitize_text_field((string)($item['title']??'')),
                'payload_json'=>$payload,
                'status'=>'pending',
            );
            if(false===$wpdb->replace(self::items_table(),$row))throw new RuntimeException('No se pudo guardar una fuente en la foto fija de L'.$lesson.'.');
        }

        $more=!empty($batch['more']);
        $next_cursor=$more?absint($batch['cursor']):$sequence;
        self::save(array(
            'snapshot_cursor'=>$next_cursor,
            'last_message'=>sprintf('L%d · foto fija: %d fuentes analizadas.',$lesson,$next_cursor),
        ));
        self::log('snapshot_batch',array('lesson'=>$lesson,'cursor'=>$next_cursor,'items'=>count((array)$batch['items'])));

        if($more){self::queue();return count((array)$batch['items']);}

        $items=self::items_table();
        $count=absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$items} WHERE snapshot_id=%d",$snapshot_id)));
        if($count<1)throw new RuntimeException('L'.$lesson.' no ha encontrado material formativo; no se inicia el aprendizaje.');
        $signatures=$wpdb->get_col($wpdb->prepare("SELECT signature FROM {$items} WHERE snapshot_id=%d ORDER BY sequence_no ASC,id ASC",$snapshot_id));
        $signature=hash('sha256',implode('|',array_map('strval',(array)$signatures)));
        $wpdb->update(self::snapshots_table(),array(
            'status'=>'ready','source_count'=>$count,'source_signature'=>$signature,'sealed_at'=>current_time('mysql'),
        ),array('id'=>$snapshot_id));
        self::save(array(
            'phase'=>'learning','learning_cursor'=>0,
            'last_message'=>sprintf('L%d · foto fija cerrada con %d fuentes. Empieza el aprendizaje sobre esa foto.',$lesson,$count),
        ));
        self::log('snapshot_sealed',array('lesson'=>$lesson,'snapshot_id'=>$snapshot_id,'sources'=>$count,'signature'=>$signature));
        self::queue();
        return count((array)$batch['items']);
    }

    private static function learn_snapshot($state){
        global $wpdb;
        $lesson=absint($state['lesson']);
        $snapshot_id=absint($state['snapshot_id']);
        if(!$snapshot_id)throw new RuntimeException('Falta la foto fija de L'.$lesson.'.');
        $run=(string)$state['run_id'];
        $items=self::items_table();
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$items} WHERE snapshot_id=%d AND status='pending' ORDER BY sequence_no ASC,id ASC LIMIT %d",
            $snapshot_id,TCD_Load::batch_size()
        ),ARRAY_A);

        if(!$rows){self::complete_lesson($state);return 0;}

        $learned=0;$unlearned=0;$last=absint($state['learning_cursor']);
        foreach($rows as $row){
            if(get_option('tcd_learning_stop','')===$run)return $learned+$unlearned;
            $item=json_decode((string)$row['payload_json'],true);
            $item=is_array($item)?$item:array();
            $reason=TCD_Sources::validate_snapshot($item);
            $status=$reason?'unlearned':'learned';
            $knowledge=array(
                'lesson'=>$lesson,
                'source_type'=>sanitize_key((string)$row['source_type']),
                'source_id'=>absint($row['source_id']),
                'signature'=>(string)$row['signature'],
                'title'=>(string)$row['title'],
                'context_json'=>wp_json_encode((array)($item['facts']??array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'status'=>$status,
                'reason'=>$reason,
                'learned_at'=>current_time('mysql'),
            );
            if(false===$wpdb->replace(self::knowledge_table(),$knowledge))throw new RuntimeException('Error al guardar conocimiento de L'.$lesson.'.');
            $wpdb->update($items,array('status'=>$status,'reason'=>$reason,'learned_at'=>current_time('mysql')),array('id'=>absint($row['id'])));
            $last=absint($row['sequence_no']);
            if($reason)$unlearned++;else $learned++;
        }

        $snapshot=self::snapshot($snapshot_id);
        $wpdb->update(self::snapshots_table(),array(
            'status'=>'learning',
            'learned_count'=>absint($snapshot['learned_count']??0)+$learned,
            'unlearned_count'=>absint($snapshot['unlearned_count']??0)+$unlearned,
        ),array('id'=>$snapshot_id));

        self::save(array(
            'learning_cursor'=>$last,
            'processed'=>absint($state['processed'])+$learned+$unlearned,
            'learned'=>absint($state['learned'])+$learned,
            'unlearned'=>absint($state['unlearned'])+$unlearned,
            'last_message'=>sprintf('L%d · aprendizaje: +%d aprendidos, +%d no aprendidos.',$lesson,$learned,$unlearned),
        ));
        self::log('learning_batch',array('lesson'=>$lesson,'snapshot_id'=>$snapshot_id,'cursor'=>$last,'learned'=>$learned,'unlearned'=>$unlearned));
        self::queue();
        return count($rows);
    }

    private static function complete_lesson($state){
        global $wpdb;
        $lesson=absint($state['lesson']);
        $snapshot_id=absint($state['snapshot_id']);
        $snapshot=self::snapshot($snapshot_id);
        $learned=absint($snapshot['learned_count']??0);
        if($learned<1)throw new RuntimeException('L'.$lesson.' no tiene ninguna fuente aprendida; no se considera completada.');
        $wpdb->update(self::snapshots_table(),array(
            'status'=>'completed','completed_at'=>current_time('mysql'),
        ),array('id'=>$snapshot_id));
        $next=$lesson+1;
        self::save(array(
            'lesson'=>$next,'phase'=>'snapshot','snapshot_id'=>0,'snapshot_cursor'=>0,'learning_cursor'=>0,
            'status'=>$next>10?'completed':'running',
            'last_message'=>$next>10?'Las diez lecciones se han completado.':sprintf('L%d completada. Preparando el análisis de L%d.',$lesson,$next),
        ));
        self::log('lesson_completed',array(
            'lesson'=>$lesson,'snapshot_id'=>$snapshot_id,'sources'=>absint($snapshot['source_count']??0),
            'learned'=>$learned,'unlearned'=>absint($snapshot['unlearned_count']??0),
        ));
        if($next<=10)self::queue();
    }

    private static function snapshot($id){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::snapshots_table()." WHERE id=%d",absint($id)),ARRAY_A);
        return is_array($row)?$row:array();
    }

    public static function snapshots(){
        global $wpdb;
        return (array)$wpdb->get_results("SELECT * FROM ".self::snapshots_table()." ORDER BY lesson ASC,id DESC",ARRAY_A);
    }

    public static function snapshot_for_lesson($lesson){
        global $wpdb;
        $run=(string)(self::state()['run_id']??'');
        if(!$run)return array();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM ".self::snapshots_table()." WHERE run_id=%s AND lesson=%d ORDER BY id DESC LIMIT 1",
            $run,absint($lesson)
        ),ARRAY_A);
        return is_array($row)?$row:array();
    }

    public static function counts(){
        global $wpdb;$table=self::knowledge_table();
        return (array)$wpdb->get_results("SELECT lesson,status,COUNT(*) total FROM {$table} GROUP BY lesson,status ORDER BY lesson,status",ARRAY_A);
    }

    public static function export_url($lesson=0){
        return wp_nonce_url(
            add_query_arg(array('action'=>'tcd_dependiente_export_json','lesson'=>absint($lesson)),admin_url('admin-post.php')),
            'tcd_dependiente_export_json'
        );
    }

    public static function export_json(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Permisos insuficientes.');
        check_admin_referer('tcd_dependiente_export_json');
        $lesson=isset($_GET['lesson'])?absint(wp_unslash($_GET['lesson'])):0;
        if($lesson>10)$lesson=0;
        $state=self::state();
        $filename=$lesson?'dependiente_l'.$lesson.'_'.gmdate('Ymd_His').'.json':'dependiente_aprendizaje_'.gmdate('Ymd_His').'.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        echo '{"schema":{"name":"taxocommerce_dependiente_learning","version":1},';
        echo '"exported_at":'.wp_json_encode(gmdate('c')).',';
        echo '"run_id":'.wp_json_encode((string)($state['run_id']??'')).',';
        echo '"state":'.wp_json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).',';
        echo '"lessons":[';
        $first_lesson=true;
        $from=$lesson?:1;$to=$lesson?:10;
        for($no=$from;$no<=$to;$no++){
            $snapshot=self::snapshot_for_lesson($no);
            if(!$snapshot)continue;
            if(!$first_lesson)echo ',';
            $first_lesson=false;
            $syllabus=json_decode((string)$snapshot['syllabus_json'],true);
            echo '{"lesson":'.$no.',"syllabus":'.wp_json_encode($syllabus,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).',';
            $meta=$snapshot;unset($meta['syllabus_json']);
            echo '"snapshot":'.wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).',"items":[';
            self::stream_snapshot_items(absint($snapshot['id']));
            echo ']}';
        }
        echo ']}';
        exit;
    }

    private static function stream_snapshot_items($snapshot_id){
        global $wpdb;
        $table=self::items_table();
        $last=0;$first=true;
        do{
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE snapshot_id=%d AND id>%d ORDER BY id ASC LIMIT 200",
                $snapshot_id,$last
            ),ARRAY_A);
            foreach((array)$rows as $row){
                $last=absint($row['id']);
                $payload=json_decode((string)$row['payload_json'],true);
                $out=array(
                    'id'=>$last,
                    'sequence_no'=>absint($row['sequence_no']),
                    'source'=>array(
                        'type'=>(string)$row['source_type'],'id'=>absint($row['source_id']),
                        'key'=>(string)$row['source_key'],'title'=>(string)$row['title'],
                        'signature'=>(string)$row['signature'],
                    ),
                    'material'=>$payload,
                    'result'=>array(
                        'status'=>(string)$row['status'],
                        'reason'=>(string)$row['reason'],
                        'learned_at'=>$row['learned_at']?:null,
                    ),
                );
                if(!$first)echo ',';
                $first=false;
                echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
        }while(count((array)$rows)===200);
    }
}
