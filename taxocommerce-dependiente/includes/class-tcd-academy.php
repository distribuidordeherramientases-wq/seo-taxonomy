<?php
defined('ABSPATH') || exit;
final class TCD_Academy {
    const CRON_HOOK='tcd_academy_tick';
    const STATE='tcd_academy_state';
    const LOG='tcd_academy_log';
    const BATCH=8;
    public static function knowledge_table() {global $wpdb;return $wpdb->prefix.'tcd_knowledge';}
    public static function install() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::knowledge_table();
        dbDelta("CREATE TABLE {$table} (
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
            PRIMARY KEY  (id),
            UNIQUE KEY unique_source (lesson,source_type,source_id),
            KEY lesson_status (lesson,status)
        ) ".$wpdb->get_charset_collate().";");
        if(false===get_option(self::STATE,false))add_option(self::STATE,self::initial_state(),' ',false);
    }
    private static function initial_state(){return array('status'=>'idle','lesson'=>1,'cursor'=>0,'processed'=>0,'learned'=>0,'unlearned'=>0,'run_id'=>'','last_error'=>'','last_message'=>'Preparado para estudiar L1–L10.','updated_at'=>'');}
    public static function state(){return wp_parse_args((array)get_option(self::STATE,array()),self::initial_state());}
    public static function save($changes){
        $state=self::state();
        $stop=get_option('tcd_academy_stop', '');
        if($stop && $stop===($state['run_id']??'') && 'running'===($changes['status']??''))return $state;
        $state=array_merge($state,(array)$changes);
        if($stop && $stop===($state['run_id']??''))$state['status']='stopped';
        $state['updated_at']=current_time('mysql');
        update_option(self::STATE,$state,false);
        return $state;
    }
    public static function log($event,$context=array()){
        $rows=(array)get_option(self::LOG,array());
        $rows[]=array('at'=>gmdate('c'),'event'=>sanitize_key($event),'context'=>array_map('sanitize_text_field',(array)$context));
        update_option(self::LOG,array_slice($rows,-75),false);
    }
    public static function logs(){return (array)get_option(self::LOG,array());}
    public static function boot(){
        add_action(self::CRON_HOOK,array(__CLASS__,'tick'));
        add_action('admin_post_tcd_academy_control',array(__CLASS__,'control'));
    }
    public static function control(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Permisos insuficientes.');
        check_admin_referer('tcd_academy_control');
        $action=isset($_POST['tcd_action'])?sanitize_key(wp_unslash($_POST['tcd_action'])):'';
        $state=self::state();
        if('stop'===$action){
            update_option('tcd_academy_stop',(string)$state['run_id'],false);
            self::save(array('status'=>'stopped','last_message'=>'Detenido: no se iniciarán más lotes.'));
            wp_clear_scheduled_hook(self::CRON_HOOK);
            self::log('stopped',array('lesson'=>$state['lesson'],'cursor'=>$state['cursor']));
        }elseif('start'===$action && in_array($state['status'],array('idle','stopped','error'),true)){
            $run=wp_generate_uuid4();
            delete_option('tcd_academy_stop');
            self::save(array('run_id'=>$run,'status'=>'running','last_error'=>'','last_message'=>'Academia iniciada.'));
            self::queue();
            self::log('started',array('lesson'=>$state['lesson'],'cursor'=>$state['cursor']));
        }elseif('restart'===$action && in_array($state['status'],array('idle','stopped','error','completed'),true)){
            // Explicit restart never deletes source content; the course snapshot is rebuilt.
            global $wpdb;
            $wpdb->query('DELETE FROM '.self::knowledge_table());
            delete_option('tcd_academy_stop');
            self::save(array_merge(self::initial_state(),array('status'=>'running','run_id'=>wp_generate_uuid4())));
            self::queue();
            self::log('restarted');
        }
        wp_safe_redirect(admin_url('admin.php?page=taxocommerce-dependiente'));
        exit;
    }
    private static function queue(){
        if('running'!==self::state()['status'])return;
        if(!wp_next_scheduled(self::CRON_HOOK))wp_schedule_single_event(time()+3,self::CRON_HOOK);
    }
    public static function tick(){
        $state=self::state();
        if('running'!==$state['status'] || get_option('tcd_academy_stop','')===$state['run_id'])return;
        $lesson=absint($state['lesson']);
        if($lesson>10){self::save(array('status'=>'completed','last_message'=>'Diez lecciones completadas.'));return;}
        $run=$state['run_id'];
        $cursor=absint($state['cursor']);
        self::log('batch_start',array('lesson'=>$lesson,'cursor'=>$cursor));
        try{
            $batch=TCD_Sources::batch($lesson,$cursor,self::BATCH);
            if(is_wp_error($batch))throw new RuntimeException($batch->get_error_message());
            global $wpdb;
            $learned=0;$unlearned=0;
            foreach((array)$batch['items'] as $item){
                if(get_option('tcd_academy_stop','')===$run || 'running'!==self::state()['status'])break;
                $reason=TCD_Sources::validate($item);
                $status=$reason?'unlearned':'learned';
                $row=array(
                    'lesson'=>$lesson,'source_type'=>$item['source_type'],
                    'source_id'=>$item['source_id'],'signature'=>$item['signature'],
                    'title'=>$item['title'],'context_json'=>wp_json_encode($item['facts']),
                    'status'=>$status,'reason'=>$reason,'learned_at'=>current_time('mysql'),
                );
                $saved=$wpdb->replace(self::knowledge_table(),$row);
                if(false===$saved)throw new RuntimeException('Error al guardar conocimiento. '.substr((string)$wpdb->last_error,0,160));
                if($reason)$unlearned++;else $learned++;
            }
            if(get_option('tcd_academy_stop','')===$run || 'running'!==self::state()['status']){
                self::log('batch_stopped',array('lesson'=>$lesson,'cursor'=>$cursor));return;
            }
            $more=!empty($batch['more']);
            if(!$more){
                // Never certify an empty or wholly failed lesson.
                $table=self::knowledge_table();
                $learned_count=absint($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE lesson=%d AND status=%s",
                    $lesson,'learned'
                )));
                if(0===$learned_count){
                    throw new RuntimeException('L'.$lesson.' no tiene fuentes aprendidas válidas. La lección no se da por completada.');
                }
            }
            $next_lesson=$more?$lesson:$lesson+1;
            $next_cursor=$more?absint($batch['cursor']):0;
            $status=$next_lesson>10?'completed':'running';
            self::save(array('lesson'=>$next_lesson,'cursor'=>$next_cursor,'status'=>$status,
                'processed'=>absint($state['processed'])+$learned+$unlearned,
                'learned'=>absint($state['learned'])+$learned,'unlearned'=>absint($state['unlearned'])+$unlearned,
                'last_message'=>sprintf('L%d: %d aprendidos, %d no aprendidos en este lote.',$lesson,$learned,$unlearned)));
            self::log('batch_finished',array('lesson'=>$lesson,'cursor'=>$next_cursor,'learned'=>$learned,'unlearned'=>$unlearned));
            if('running'===$status)self::queue();
        }catch(Throwable $e){
            self::save(array('status'=>'error','last_error'=>substr($e->getMessage(),0,300),'last_message'=>'Academia detenida por error. Consulta el registro.'));
            self::log('batch_error',array('lesson'=>$lesson,'cursor'=>$cursor,'error'=>substr($e->getMessage(),0,200)));
            error_log('[TaxoCommerce Dependiente] '.substr($e->getMessage(),0,300));
        }
    }
    public static function counts(){
        global $wpdb;$table=self::knowledge_table();
        return (array)$wpdb->get_results("SELECT lesson,status,COUNT(*) total FROM {$table} GROUP BY lesson,status ORDER BY lesson,status",ARRAY_A);
    }
}
