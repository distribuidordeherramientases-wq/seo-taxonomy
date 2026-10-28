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
    const WATCHDOG='tcd_learning_watchdog_last';
    const SCHEMA_OPTION='tcd_learning_schema_version';
    const SCHEMA_VERSION='3.0.0';

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
            'last_message'=>'Preparado para L1-L10, construcción del Intérprete y mantenimiento continuo.',
            'updated_at'=>'',
            'load_last'=>array(),
            'identity_backfill_done'=>0,
            'identity_backfill_total'=>0,
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
        $event=sanitize_key($event);
        $rows[]=array('at'=>gmdate('c'),'event'=>$event,'context'=>$clean);
        update_option(self::LOG,array_slice($rows,-150),false);

        $level=(false!==strpos($event,'error') || false!==strpos($event,'fatal'))?'ERROR':'INFO';
        self::trace($level,$event,$context);
    }

    public static function logs(){return (array)get_option(self::LOG,array());}

    private static function trace($level,$event,$context=array()){
        if(class_exists('TCD_Learning_Log',false))TCD_Learning_Log::write($level,$event,$context);
    }

    private static function trace_item($event,$item,$extra=array()){
        $item=is_array($item)?$item:array();
        $facts=(array)($item['facts']??array());
        $vocabulary=array();
        foreach((array)($facts['vocabulary']??array()) as $group=>$rows){
            $labels=array();
            foreach(array_slice((array)$rows,0,5) as $row){
                if(is_array($row))$labels[]=trim((string)($row['label']??$row['name']??$row['slug']??''));
            }
            $vocabulary[sanitize_key((string)$group)]=array_values(array_filter($labels));
        }
        $context=array(
            'source_type'=>sanitize_key((string)($item['source_type']??'')),
            'source_id'=>absint($item['source_id']??0),
            'source_key'=>sanitize_text_field((string)($item['source_key']??'')),
            'title'=>sanitize_text_field((string)($item['title']??'')),
            'signature'=>substr((string)($item['signature']??''),0,16),
            'fact_keys'=>array_keys($facts),
            'vocabulary'=>$vocabulary,
            'sku'=>(string)($facts['sku']??''),
            'categories'=>$facts['categories']??array(),
            'brands'=>$facts['brands']??array(),
            'body_bytes'=>strlen((string)($item['body']??'')),
        );
        self::trace('DEBUG',$event,array_merge($context,(array)$extra));
    }

    private static function exception_context($e){
        return array(
            'exception'=>is_object($e)?get_class($e):'unknown',
            'error'=>is_object($e)?substr((string)$e->getMessage(),0,1000):'',
            'file'=>is_object($e)?wp_basename((string)$e->getFile()):'',
            'line'=>is_object($e)?absint($e->getLine()):0,
            'trace'=>is_object($e)?substr((string)$e->getTraceAsString(),0,5000):'',
        );
    }

    private static function trace_db_error($operation,$table=''){
        global $wpdb;
        self::trace('ERROR','db_error',array(
            'operation'=>sanitize_key((string)$operation),
            'table'=>(string)$table,
            'db_error'=>substr((string)$wpdb->last_error,0,2000),
            'last_query'=>substr((string)$wpdb->last_query,0,3000),
        ));
    }

    public static function boot(){
        self::maybe_upgrade();
        add_action(self::CRON_HOOK,array(__CLASS__,'tick'));
        add_action('admin_init',array(__CLASS__,'watchdog_running_process'));
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

            $retry=array();
            $lesson=absint($state['lesson']??0);
            $snapshot=$lesson?self::snapshot_for_lesson($lesson):array();
            if(
                $snapshot
                && 'quality_failed'===(string)($snapshot['status']??'')
                && class_exists('TCD_Intelligence',false)
                && method_exists('TCD_Intelligence','prepare_quality_retry')
            ){
                $retry=TCD_Intelligence::prepare_quality_retry($lesson,absint($snapshot['id']??0));
            }

            delete_option('tcd_learning_stop');
            delete_option(self::WATCHDOG);
            $message=$retry
                ? sprintf(
                    'Correcciones revisadas: %d fuentes releídas, %d casos reaprendidos. Reevaluando L%d sin reiniciar la formación.',
                    absint($retry['refreshed']??0),
                    absint($retry['relearned']??0),
                    $lesson
                )
                : 'Dependiente iniciado. Primero analizará la lección y cerrará su foto fija.';
            $changes=array('run_id'=>$run,'status'=>'running','last_error'=>'','last_message'=>$message);
            if($retry)$changes['phase']='quality';
            self::save($changes);
            self::queue();
            self::nudge_manager();
            self::log('started',array('lesson'=>$state['lesson'],'phase'=>$state['phase'],'run'=>$run,'quality_retry'=>$retry));
        }elseif('restart'===$action && in_array($state['status'],array('idle','stopped','error','completed'),true)){
            self::clear_learning_tables();
            delete_option('tcd_learning_stop');
            delete_option(self::WATCHDOG);
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
        if(class_exists('TCD_Intelligence'))TCD_Intelligence::reset_modern_generated();
        if(class_exists('TCD_Maintenance'))TCD_Maintenance::deactivate();
    }

    public static function watchdog_running_process(){
        if(!is_admin())return;

        $state=self::state();
        if('running'!==(string)($state['status']??''))return;

        $run=(string)($state['run_id']??'');
        if($run && get_option('tcd_learning_stop','')===$run)return;

        $now=time();
        $last=absint(get_option(self::WATCHDOG,0));
        if($last && ($now-$last)<15)return;
        update_option(self::WATCHDOG,$now,false);

        $lock=absint(get_option(self::LOCK,0));
        $lock_age=$lock?max(0,$now-$lock):0;
        if($lock && $lock_age<120)return;

        $next=wp_next_scheduled(self::CRON_HOOK);
        $cron_repaired=false;
        if(!$next || $next<($now-15) || $next>($now+60)){
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_single_event($now+1,self::CRON_HOOK);
            $cron_repaired=true;
        }

        self::nudge_manager(0);
        self::trace('INFO','watchdog_wakeup',array(
            'lesson'=>absint($state['lesson']??0),
            'phase'=>(string)($state['phase']??''),
            'snapshot_cursor'=>absint($state['snapshot_cursor']??0),
            'learning_cursor'=>absint($state['learning_cursor']??0),
            'cron_repaired'=>$cron_repaired?1:0,
            'next_tick'=>wp_next_scheduled(self::CRON_HOOK)?:0,
            'lock_age'=>$lock_age,
        ));
    }

    private static function queue($delay=null){
        if('running'!==self::state()['status'])return;
        $delay=null===$delay ? TCD_Load::delay() : max(1,absint($delay));
        $existing=wp_next_scheduled(self::CRON_HOOK);
        if(!$existing)wp_schedule_single_event(time()+$delay,self::CRON_HOOK);
        $next=wp_next_scheduled(self::CRON_HOOK);
        self::trace('DEBUG','queue_scheduled',array(
            'delay'=>$delay,
            'already_scheduled'=>$existing?1:0,
            'next_tick'=>$next?gmdate('c',$next):'none',
        ));
        self::nudge_manager($delay);
    }

    private static function nudge_manager($delay=0){
        if(function_exists('seo_process_supervisor_nudge')){
            self::trace('DEBUG','manager_nudge',array('delay'=>max(0,absint($delay))));
            seo_process_supervisor_nudge(max(0,absint($delay)),'dependiente');
        }else{
            self::trace('WARN','manager_nudge_unavailable');
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
        self::trace('DEBUG','manager_callback',array('budget'=>$budget,'source'=>(string)$source));
        $worked=false;
        $iterations=0;
        // Consume a bounded number of consecutive chunks while budget
        // remains. A formerly single-tick callback left CPU idle even at 5.
        $max_ticks=TCD_Load::priority()===5 ? 4 : (TCD_Load::priority()===4 ? 2 : 1);
        while($iterations<$max_ticks){
            if('running'!==(string)(self::state()['status']??''))break;
            if(get_option('tcd_learning_stop','')===(string)(self::state()['run_id']??''))break;
            if($iterations>0 && microtime(true)-$started>=max(1,$budget-2))break;
            self::tick();
            $worked=true;
            ++$iterations;
        }
        return $worked;
    }

    public static function tick(){
        $state=self::state();
        if('running'!==$state['status'] || get_option('tcd_learning_stop','')===$state['run_id']){
            self::trace('DEBUG','tick_skipped_state',array('status'=>$state['status']??'','stop'=>get_option('tcd_learning_stop','')?1:0));
            return;
        }
        self::trace('DEBUG','tick_enter',array(
            'lesson'=>absint($state['lesson']??0),
            'phase'=>(string)($state['phase']??''),
            'snapshot_cursor'=>absint($state['snapshot_cursor']??0),
            'learning_cursor'=>absint($state['learning_cursor']??0),
        ));
        $lock=get_option(self::LOCK,0);
        $lock_age=$lock?max(0,time()-absint($lock)):0;
        if($lock && $lock_age<120){
            self::trace('WARN','tick_locked',array('lock_age'=>$lock_age,'retry_in'=>15));
            self::queue(15);
            return;
        }
        if(!add_option(self::LOCK,time(),'',false)){
            if($lock && $lock_age>=120){
                self::trace('WARN','stale_lock_recovered',array('lock_age'=>$lock_age));
                delete_option(self::LOCK);
                self::queue(3);
            }else{
                self::trace('WARN','lock_acquire_failed',array('lock_age'=>$lock_age,'retry_in'=>15));
                self::queue(15);
            }
            return;
        }
        self::trace('DEBUG','lock_acquired');
        try{
            self::run_one($state);
        }finally{
            delete_option(self::LOCK);
            self::trace('DEBUG','lock_released');
        }
    }

    private static function run_one($state){
        $lesson=absint($state['lesson']);
        if(11===$lesson){
            $measure=TCD_Load::begin();
            try{
                $built=TCD_Intelligence::build_interpreter();
                if(is_wp_error($built))throw new RuntimeException( esc_html( $built->get_error_message()) );
                $load=TCD_Load::finish($measure,absint($built['batch_evaluated']??0));
                if(empty($built['done'])){
                    self::save(array(
                        'status'=>'running',
                        'load_last'=>$load,
                        'last_message'=>sprintf(
                            'M11 · validando Intérprete con el motor actual: %d/%d casos · %d aciertos provisionalmente.',
                            absint($built['tested']??0),absint($built['total']??0),absint($built['passed']??0)
                        ),
                    ));
                    self::log('interpreter_progress',array(
                        'tested'=>absint($built['tested']??0),
                        'total'=>absint($built['total']??0),
                        'passed'=>absint($built['passed']??0),
                        'batch'=>absint($built['batch_evaluated']??0),
                    ));
                    self::queue();
                    return;
                }
                self::save(array(
                    'lesson'=>12,'phase'=>'maintenance','status'=>'running','last_error'=>'',
                    'load_last'=>$load,
                    'last_message'=>sprintf('M11 · Intérprete validado: %d reglas, %d señales L9, regresión %.1f%% (%d/%d).',
                        absint($built['rules']??0),absint($built['l9_memory']??0),
                        100*(float)($built['pass_any_ratio']??0),absint($built['passed']??0),absint($built['tested']??0))
                ));
                self::log('interpreter_built',$built);
                self::queue();
            }catch(Throwable $e){
                $load=TCD_Load::finish($measure,0);
                self::save(array('status'=>'error','load_last'=>$load,'last_error'=>substr($e->getMessage(),0,400),'last_message'=>'M11 · Intérprete detenido por regresión o error.'));
                self::log('interpreter_error',array('error'=>substr($e->getMessage(),0,240)));
            }
            return;
        }
        if(12===$lesson){
            $maintenance=TCD_Maintenance::activate();
            self::save(array(
                'status'=>'completed','phase'=>'maintenance','last_error'=>'',
                'last_message'=>'M12 · Actualización continua activa. El curso L1-L10 y el Intérprete están completados.'
            ));
            self::log('maintenance_activated',$maintenance);
            return;
        }
        if($lesson>12){
            self::save(array('status'=>'completed','last_message'=>'Dependiente está aprendido y en mantenimiento continuo.'));
            return;
        }
        $measure=TCD_Load::begin();
        $processed=0;
        self::trace('INFO','run_one_begin',array(
            'lesson'=>$lesson,
            'phase'=>(string)($state['phase']??''),
            'snapshot_id'=>absint($state['snapshot_id']??0),
            'snapshot_cursor'=>absint($state['snapshot_cursor']??0),
            'learning_cursor'=>absint($state['learning_cursor']??0),
            'batch'=>TCD_Load::batch_size(),
        ));
        try{
            $backfill=TCD_Intelligence::backfill_next_missing($lesson);
            if(is_wp_error($backfill))throw new RuntimeException( esc_html( $backfill->get_error_message()) );
            if(is_array($backfill)){
                $processed=absint($backfill['count']??0);
                $load=TCD_Load::finish($measure,$processed);
                self::save(array(
                    'load_last'=>$load,
                    'last_message'=>sprintf('Migración interna: L%d existente reevaluada y consolidada antes de continuar.',absint($backfill['lesson']??0))
                ));
                self::log('lesson_backfilled',array(
                    'lesson'=>absint($backfill['lesson']??0),
                    'count'=>$processed,
                    'pass_any_ratio'=>(float)($backfill['quality']['pass_any_ratio']??0),
                ));
                self::queue();
                return;
            }
            if('snapshot'===(string)$state['phase']){
                $processed=self::build_snapshot($state);
            }elseif('quality'===(string)$state['phase']){
                $processed=self::evaluate_quality($state);
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
            self::trace('ERROR','learning_exception',array_merge(
                array('lesson'=>$lesson,'phase'=>$state['phase'],'processed'=>$processed,'load'=>$load),
                self::exception_context($e)
            ));
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
        if(false===$wpdb->insert(self::snapshots_table(),$row)){
            self::trace_db_error('snapshot_create',self::snapshots_table());
            throw new RuntimeException( esc_html( 'No se pudo crear la foto fija de L'.$lesson.'.') );
        }
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
        $limit=TCD_Load::batch_size();
        self::trace('INFO','source_batch_request',array('lesson'=>$lesson,'cursor'=>$cursor,'limit'=>$limit));
        $batch=TCD_Sources::batch($lesson,$cursor,$limit);
        if(is_wp_error($batch)){
            self::trace('ERROR','source_batch_error',array('lesson'=>$lesson,'cursor'=>$cursor,'limit'=>$limit,'error'=>$batch->get_error_message()));
            throw new RuntimeException( esc_html( $batch->get_error_message()) );
        }
        self::trace('INFO','source_batch_read',array(
            'lesson'=>$lesson,'cursor'=>$cursor,'limit'=>$limit,
            'returned'=>count((array)($batch['items']??array())),
            'next_cursor'=>absint($batch['cursor']??0),'more'=>!empty($batch['more'])?1:0,
        ));

        $sequence=$cursor;
        foreach((array)$batch['items'] as $item){
            if(get_option('tcd_learning_stop','')===$run)return;
            $sequence++;
            self::trace_item('source_read',$item,array('sequence'=>$sequence,'snapshot_id'=>$snapshot_id));
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
            if(false===$wpdb->replace(self::items_table(),$row)){
                self::trace_db_error('snapshot_item_write',self::items_table());
                throw new RuntimeException( esc_html( 'No se pudo guardar una fuente en la foto fija de L'.$lesson.'.') );
            }
            self::trace('DEBUG','snapshot_write',array(
                'snapshot_id'=>$snapshot_id,'sequence'=>$sequence,
                'source_type'=>$row['source_type'],'source_id'=>$row['source_id'],
                'title'=>$row['title'],'payload_bytes'=>strlen($payload),'status'=>'pending',
            ));
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
        if($count<1)throw new RuntimeException( esc_html( 'L'.$lesson.' no ha encontrado material formativo; no se inicia el aprendizaje.') );
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
        if(!$snapshot_id)throw new RuntimeException( esc_html( 'Falta la foto fija de L'.$lesson.'.') );
        $run=(string)$state['run_id'];
        $items=self::items_table();
        $limit=TCD_Load::batch_size();
        self::trace('INFO','knowledge_batch_request',array(
            'snapshot_id'=>$snapshot_id,'lesson'=>$lesson,'limit'=>$limit,
            'after_sequence'=>absint($state['learning_cursor']??0),
        ));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$items} WHERE snapshot_id=%d AND status='pending' ORDER BY sequence_no ASC,id ASC LIMIT %d",
            $snapshot_id,$limit
        ),ARRAY_A);
        if($wpdb->last_error)self::trace_db_error('knowledge_batch_read',$items);
        self::trace('INFO','knowledge_batch_read',array(
            'snapshot_id'=>$snapshot_id,'lesson'=>$lesson,'returned'=>count((array)$rows),
            'first_sequence'=>$rows?absint($rows[0]['sequence_no']??0):0,
            'last_sequence'=>$rows?absint($rows[count($rows)-1]['sequence_no']??0):0,
        ));

        if(!$rows){
            TCD_Intelligence::reset_quality_progress($lesson,$snapshot_id);
            self::save(array(
                'phase'=>'quality',
                'last_message'=>sprintf('L%d · aprendizaje terminado. Preparando examen de calidad.',$lesson),
            ));
            self::log('quality_started',array('lesson'=>$lesson,'snapshot_id'=>$snapshot_id));
            self::queue();
            return 0;
        }

        $learned=0;$unlearned=0;$last=absint($state['learning_cursor']);
        foreach($rows as $row){
            if(get_option('tcd_learning_stop','')===$run)return $learned+$unlearned;
            $item=json_decode((string)$row['payload_json'],true);
            $item=is_array($item)?$item:array();
            self::trace_item('learning_item_read',$item,array(
                'snapshot_row_id'=>absint($row['id']??0),
                'sequence'=>absint($row['sequence_no']??0),
                'payload_bytes'=>strlen((string)($row['payload_json']??'')),
            ));
            $reason=TCD_Sources::validate_snapshot($item);
            $status=$reason?'unlearned':'learned';
            self::trace($reason?'WARN':'DEBUG','learning_validation',array(
                'sequence'=>absint($row['sequence_no']??0),
                'source_type'=>(string)($row['source_type']??''),
                'source_id'=>absint($row['source_id']??0),
                'status'=>$status,'reason'=>$reason,
            ));
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
            if(false===$wpdb->replace(self::knowledge_table(),$knowledge)){
                self::trace_db_error('knowledge_write',self::knowledge_table());
                throw new RuntimeException( esc_html( 'Error al guardar conocimiento de L'.$lesson.'.') );
            }
            self::trace('INFO','knowledge_write',array(
                'lesson'=>$lesson,'sequence'=>absint($row['sequence_no']??0),
                'source_type'=>$knowledge['source_type'],'source_id'=>$knowledge['source_id'],
                'title'=>$knowledge['title'],'status'=>$status,'reason'=>$reason,
                'context_bytes'=>strlen((string)$knowledge['context_json']),
            ));
            if('learned'===$status && class_exists('TCD_Intelligence')){
                self::trace('DEBUG','intelligence_learn_begin',array(
                    'lesson'=>$lesson,'source_type'=>$knowledge['source_type'],'source_id'=>$knowledge['source_id'],
                ));
                TCD_Intelligence::learn_item($lesson,$item,$snapshot_id);
                self::trace('DEBUG','intelligence_learn_end',array(
                    'lesson'=>$lesson,'source_type'=>$knowledge['source_type'],'source_id'=>$knowledge['source_id'],
                ));
            }
            if(false===$wpdb->update($items,array('status'=>$status,'reason'=>$reason,'learned_at'=>current_time('mysql')),array('id'=>absint($row['id'])))){
                self::trace_db_error('snapshot_item_mark',$items);
            }
            $last=absint($row['sequence_no']);
            if($reason)$unlearned++;else $learned++;
        }

        $snapshot=self::snapshot($snapshot_id);
        $new_learned=absint($snapshot['learned_count']??0)+$learned;
        $new_unlearned=absint($snapshot['unlearned_count']??0)+$unlearned;
        if(false===$wpdb->update(self::snapshots_table(),array(
            'status'=>'learning',
            'learned_count'=>$new_learned,
            'unlearned_count'=>$new_unlearned,
        ),array('id'=>$snapshot_id))){
            self::trace_db_error('snapshot_progress_write',self::snapshots_table());
        }
        self::trace('INFO','learning_progress',array(
            'snapshot_id'=>$snapshot_id,'lesson'=>$lesson,
            'batch_learned'=>$learned,'batch_unlearned'=>$unlearned,
            'learned_total'=>$new_learned,'unlearned_total'=>$new_unlearned,
            'snapshot_total'=>absint($snapshot['source_count']??0),
            'cursor'=>$last,
        ));

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

    private static function evaluate_quality($state){
        global $wpdb;
        $lesson=absint($state['lesson']);
        $snapshot_id=absint($state['snapshot_id']);
        $snapshot=self::snapshot($snapshot_id);
        $learned=absint($snapshot['learned_count']??0);
        if($learned<1)throw new RuntimeException( esc_html( 'L'.$lesson.' no tiene ninguna fuente aprendida; no se considera completada.') );

        if(
            2===$lesson
            && method_exists('TCD_Intelligence','l2_identity_backfill_step')
        ){
            $identity=TCD_Intelligence::l2_identity_backfill_step($snapshot_id);
            if(is_wp_error($identity))throw new RuntimeException( esc_html( $identity->get_error_message()) );

            $identity_done=absint($identity['processed']??0);
            $identity_total=absint($identity['total']??0);
            self::save(array(
                'identity_backfill_done'=>$identity_done,
                'identity_backfill_total'=>$identity_total,
            ));

            if(empty($identity['done'])){
                $percent=$identity_total>0 ? round(100*$identity_done/$identity_total,1) : 0;
                self::save(array(
                    'last_message'=>sprintf(
                        'L2 · preparando memoria de identidad desde la foto fija: %d / %d productos · %.1f%%.',
                        $identity_done,$identity_total,$percent
                    ),
                ));
                self::log('l2_identity_backfill_progress',array(
                    'snapshot_id'=>$snapshot_id,
                    'processed'=>$identity_done,
                    'total'=>$identity_total,
                    'remaining'=>absint($identity['remaining']??0),
                    'batch'=>absint($identity['batch']??0),
                    'written'=>absint($identity['written']??0),
                    'percent'=>$percent,
                ));
                self::queue();
                return max(1,absint($identity['batch']??1));
            }

            if($identity_total>0 && $identity_done>0){
                self::log('l2_identity_backfill_completed',array(
                    'snapshot_id'=>$snapshot_id,
                    'processed'=>$identity_done,
                    'total'=>$identity_total,
                ));
            }
        }

        $step=TCD_Intelligence::quality_step($lesson,$snapshot_id);
        if(is_wp_error($step))throw new RuntimeException( esc_html( $step->get_error_message()) );

        if(empty($step['done'])){
            $evaluated=absint($step['evaluated']??0);
            $total=absint($step['total']??0);
            $percent=(float)($step['percent']??0);
            self::save(array(
                'last_message'=>sprintf(
                    'L%d · examen de calidad: %d / %d casos evaluados · %.1f%% · Top8 provisional %d · fallos provisionales %d.',
                    $lesson,$evaluated,$total,$percent,absint($step['pass_top8']??0),absint($step['failed']??0)
                ),
            ));
            self::log('quality_progress',array(
                'lesson'=>$lesson,'snapshot_id'=>$snapshot_id,'evaluated'=>$evaluated,'total'=>$total,
                'percent'=>$percent,'top1'=>absint($step['pass_top1']??0),'top3'=>absint($step['pass_top3']??0),
                'top8'=>absint($step['pass_top8']??0),'failed'=>absint($step['failed']??0),
            ));
            self::queue(1);
            return max(1,absint($step['batch_evaluated']??1));
        }

        $quality=(array)($step['report']??array());
        if(empty($step['passed'])){
            $wpdb->update(self::snapshots_table(),array('status'=>'quality_failed'),array('id'=>$snapshot_id));
            throw new RuntimeException( esc_html( sprintf(
                'L%d no supera el control de calidad: %.1f%%, mínimo %.1f%%.',
                $lesson,100*(float)($quality['pass_any_ratio']??0),100*(float)($quality['threshold']??0)
            ) ) );
        }

        $wpdb->update(self::snapshots_table(),array(
            'status'=>'completed','completed_at'=>current_time('mysql'),
        ),array('id'=>$snapshot_id));
        $next=$lesson+1;
        self::save(array(
            'lesson'=>$next,'phase'=>$next<=10?'snapshot':'interpreter','snapshot_id'=>0,'snapshot_cursor'=>0,'learning_cursor'=>0,
            'identity_backfill_done'=>0,'identity_backfill_total'=>0,
            'status'=>'running',
            'last_message'=>$next<=10
                ? sprintf('L%d superada con %.1f%%. Preparando L%d.',$lesson,100*(float)($quality['pass_any_ratio']??0),$next)
                : sprintf('L10 superada con %.1f%%. Preparando M11 · Intérprete.',100*(float)($quality['pass_any_ratio']??0)),
        ));
        self::log('lesson_completed',array(
            'lesson'=>$lesson,'snapshot_id'=>$snapshot_id,'sources'=>absint($snapshot['source_count']??0),
            'learned'=>$learned,'unlearned'=>absint($snapshot['unlearned_count']??0),
            'pass_any_ratio'=>(float)($quality['pass_any_ratio']??0),
            'pass_top1'=>absint($quality['pass_top1']??0),
            'pass_top3'=>absint($quality['pass_top3']??0),
        ));
        self::queue();
        return max(1,absint($step['batch_evaluated']??1));
    }

    private static function snapshot($id){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',self::snapshots_table(),absint($id)),ARRAY_A);
        return is_array($row)?$row:array();
    }

    public static function snapshots(){
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY lesson ASC,id DESC',self::snapshots_table()),ARRAY_A);
    }

    public static function snapshot_for_lesson($lesson){
        global $wpdb;
        $run=(string)(self::state()['run_id']??'');
        if(!$run)return array();
        $row=$wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE run_id=%s AND lesson=%d ORDER BY id DESC LIMIT 1',
            self::snapshots_table(),$run,absint($lesson)
        ),ARRAY_A);
        return is_array($row)?$row:array();
    }

    public static function counts(){
        global $wpdb;$table=self::knowledge_table();
        return (array)$wpdb->get_results("SELECT lesson,status,COUNT(*) total FROM {$table} GROUP BY lesson,status ORDER BY lesson,status",ARRAY_A);
    }

    public static function upsert_maintenance_item($lesson,$item){
        global $wpdb;
        $lesson=absint($lesson);
        if(!$lesson || !is_array($item))return false;
        $reason=TCD_Sources::validate_snapshot($item);
        $row=array(
            'lesson'=>$lesson,
            'source_type'=>sanitize_key((string)($item['source_type']??'')),
            'source_id'=>absint($item['source_id']??0),
            'signature'=>sanitize_text_field((string)($item['signature']??'')),
            'title'=>sanitize_text_field((string)($item['title']??'')),
            'context_json'=>wp_json_encode((array)($item['facts']??array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'status'=>$reason?'unlearned':'learned',
            'reason'=>$reason,
            'learned_at'=>current_time('mysql'),
        );
        if(!$row['source_id'] || !$row['source_type'])return false;

        // REPLACE deletes and re-inserts the row, changing its auto-increment
        // ID. Quality sampling used that ID and therefore drifted after a
        // retry. Preserve identity while refreshing the source snapshot.
        $existing_id=absint($wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE lesson=%d AND source_type=%s AND source_id=%d LIMIT 1',
            self::knowledge_table(),$lesson,$row['source_type'],$row['source_id']
        )));
        if($existing_id){
            return false!==$wpdb->update(self::knowledge_table(),$row,array('id'=>$existing_id));
        }
        return false!==$wpdb->insert(self::knowledge_table(),$row);
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

        echo '{"schema":{"name":"taxocommerce_dependiente_learning","version":2},';
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
            echo '{"lesson":'.esc_html( (string) absint( $no ) ).',"syllabus":'.wp_json_encode($syllabus,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).',';
            $meta=$snapshot;unset($meta['syllabus_json']);
            $quality=class_exists('TCD_Intelligence',false)?TCD_Intelligence::quality_for_lesson($no):array();
            $quality_report=array();
            if($quality){
                $quality_report=json_decode((string)($quality['report_json']??''),true);
                $quality_report=is_array($quality_report)?$quality_report:array();
            }
            echo '"snapshot":'.wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).',';
            echo '"quality":'.wp_json_encode(array(
                'summary'=>$quality,
                'report'=>$quality_report,
            ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).',"items":[';
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
