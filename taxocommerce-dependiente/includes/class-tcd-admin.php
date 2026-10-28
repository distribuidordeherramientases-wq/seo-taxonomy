<?php
defined('ABSPATH') || exit;

final class TCD_Admin {
    public static function boot(){
        add_action('admin_menu',array(__CLASS__,'menu'),30);
    }

    public static function menu(){
        if (function_exists('taxocommerce_core')) {
            add_submenu_page('taxocommerce-core','TaxoCommerce Sales Assistant','Sales Assistant','manage_woocommerce',
                'taxocommerce-dependiente',array(__CLASS__,'render'));
        } else {
            add_menu_page('TaxoCommerce Sales Assistant','Sales Assistant','manage_woocommerce',
                'taxocommerce-dependiente',array(__CLASS__,'render'),'dashicons-store',59);
        }
    }

    private static function control_button($action,$label,$class='button'){
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('tcd_academy_control');
        echo '<input type="hidden" name="action" value="tcd_academy_control">';
        echo '<input type="hidden" name="tcd_action" value="'.esc_attr($action).'">';
        echo '<button type="submit" class="'.esc_attr($class).'">'.esc_html($label).'</button></form>';
    }

    private static function phase_label($state){
        if('completed'===(string)($state['status']??''))return 'Aprendido · mantenimiento activo';
        if('interpreter'===(string)($state['phase']??''))return 'M11 · Construyendo / validando Intérprete';
        if('maintenance'===(string)($state['phase']??''))return 'M12 · Actualización continua';
        if('snapshot'===(string)($state['phase']??''))return 'Analizando / creando foto fija';
        if('quality'===(string)($state['phase']??''))return 'Examen de calidad / reevaluación';
        return 'Aprendiendo la foto fija';
    }

    private static function tab_url($tab){
        return add_query_arg(
            array(
                'page'=>'taxocommerce-dependiente',
                'tab'=>sanitize_key((string)$tab),
            ),
            admin_url('admin.php')
        );
    }

    private static function tabs($active){
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
        echo '<a class="nav-tab '.esc_attr('overview'===$active?'nav-tab-active':'').'" href="'.esc_url(self::tab_url('overview')).'">Dependiente</a>';
        echo '<a class="nav-tab '.esc_attr('knowledge'===$active?'nav-tab-active':'').'" href="'.esc_url(self::tab_url('knowledge')).'">Conocimiento / Importar–Exportar</a>';
        echo '<a class="nav-tab '.esc_attr('backup'===$active?'nav-tab-active':'').'" href="'.esc_url(self::tab_url('backup')).'">Backup / Restaurar</a>';
        echo '<a class="nav-tab '.esc_attr('learning-log'===$active?'nav-tab-active':'').'" href="'.esc_url(self::tab_url('learning-log')).'">Log de aprendizaje</a>';
        echo '</nav>';
    }

    private static function render_learning_log(){
        $state=TCD_Academy::state();
        $snapshot=TCD_Academy::snapshot_for_lesson(absint($state['lesson']??0));
        $log=TCD_Learning_Log::read();
        $size=TCD_Learning_Log::size();
        $modified=TCD_Learning_Log::modified_at();
        $lock=absint(get_option(TCD_Academy::LOCK,0));
        $lock_age=$lock?max(0,time()-$lock):0;
        $next=wp_next_scheduled(TCD_Academy::CRON_HOOK);
        $auto=isset($_GET['auto']) && '1'===sanitize_text_field(wp_unslash((string)$_GET['auto']));
        $warnings=substr_count($log,' WARN ');
        $errors=substr_count($log,' ERROR ');
        $processed=absint($snapshot['learned_count']??0)+absint($snapshot['unlearned_count']??0);
        $total=absint($snapshot['source_count']??0);

        echo '<p>Traza detallada del aprendizaje. Registra lecturas de fuentes, creación de foto fija, validación, escrituras en conocimiento, llamadas al motor semántico, locks, reprogramaciones, métricas y errores. El fichero activo rota automáticamente y nunca debe superar 1 MiB.</p>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:12px 0 16px">';
        self::log_card('Estado',(string)($state['status']??'—'),'L'.absint($state['lesson']??0).' · '.(string)($state['phase']??''));
        self::log_card('Última actividad',(string)($state['updated_at']??'—'),$modified?'Fichero: '.$modified:'Fichero aún vacío');
        self::log_card('Progreso actual',$total?number_format_i18n($processed).' / '.number_format_i18n($total):'—',$total?number_format_i18n(100*$processed/max(1,$total),1).'% de la foto fija':'Sin foto sellada');
        self::log_card('Lock',$lock?($lock_age.' s'):'Libre',$lock_age>=120?'Posible lock obsoleto; el siguiente tick intenta recuperarlo.':'Protege contra dos lotes simultáneos.');
        self::log_card('Próximo tick',$next?gmdate('Y-m-d H:i:s',$next).' UTC':'No programado',$next?'En '.max(0,$next-time()).' s':'Si está running, el gestor también puede ejecutar Dependiente.');
        self::log_card('Log',size_format($size).' / 1 MiB','WARN: '.number_format_i18n($warnings).' · ERROR: '.number_format_i18n($errors));
        echo '</div>';

        $sample=TCD_Load::last_sample();
        if($sample){
            echo '<div class="notice notice-info inline"><p><strong>Último lote:</strong> ';
            echo esc_html(number_format_i18n(absint($sample['items']??0))).' elementos · ';
            echo esc_html(number_format_i18n((float)($sample['elapsed']??0),3)).' s · ';
            echo 'CPU petición '.esc_html(null===($sample['cpu_percent']??null)?'n/d':number_format_i18n((float)$sample['cpu_percent'],1).'%').' · ';
            echo 'memoria '.esc_html(size_format(absint($sample['memory_peak']??0))).' · ';
            echo 'consultas '.esc_html(number_format_i18n(absint($sample['queries']??0))).'.</p></div>';
        }
        if(!empty($state['last_error'])){
            echo '<div class="notice notice-error inline"><p><strong>Último error del estado:</strong> '.esc_html((string)$state['last_error']).'</p></div>';
        }

        if ( 6 === absint( $state['lesson'] ?? 0 ) ) {
            $faq = TCD_Sources::faq_diagnostics();
            $ready = 'ready' === (string) ( $faq['status'] ?? '' );
            echo '<div class="notice ' . ( $ready ? 'notice-info' : 'notice-warning' ) . ' inline"><p><strong>Diagnóstico de la fuente L6 · FAQs:</strong> '
                . esc_html( (string) ( $faq['message'] ?? 'Sin diagnóstico' ) ) . '</p>';
            if ( ! empty( $faq['exists'] ) ) {
                echo '<p>Registros: ' . esc_html( null === $faq['total'] ? 'N/D' : number_format_i18n( $faq['total'] ) )
                    . ' · activos: ' . esc_html( null === $faq['active'] ? 'N/D' : number_format_i18n( $faq['active'] ) )
                    . ' · inactivos: ' . esc_html( null === $faq['inactive'] ? 'N/D' : number_format_i18n( $faq['inactive'] ) )
                    . ' · campo opcional product_cat_id: ' . ( null === $faq['has_product_cat_id'] ? 'no comprobado' : ( $faq['has_product_cat_id'] ? 'presente' : 'ausente (compatible)' ) ) . '.</p>';
            }
            echo '<p class="description">Comprobación de solo lectura. No cambia el contenido de las FAQs ni el estado del aprendizaje.</p></div>';
        }

        echo '<h2>Eventos recientes del proceso</h2>';
        echo '<p class="description">Resumen estructurado de los últimos eventos de Academia. Se mantiene aquí, junto al log bruto, para que toda la trazabilidad técnica de Dependiente esté centralizada en una sola pestaña.</p>';
        echo '<table class="widefat striped" style="margin-bottom:18px"><thead><tr><th>Fecha (UTC)</th><th>Evento</th><th>Detalles</th></tr></thead><tbody>';
        $academy_logs=array_reverse(array_slice(TCD_Academy::logs(),-40));
        if($academy_logs){
            foreach($academy_logs as $entry){
                echo '<tr><td>'.esc_html((string)($entry['at']??'')).'</td><td>'.esc_html((string)($entry['event']??'')).'</td><td><code>'.esc_html(wp_json_encode((array)($entry['context']??array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</code></td></tr>';
            }
        }else{
            echo '<tr><td colspan="3">Aún no hay eventos de Academia registrados.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Log bruto para diagnóstico</h2>';
        echo '<p class="description">Traza completa orientada a diagnóstico técnico y análisis por IA. Incluye más detalle que el resumen anterior: lecturas, escrituras, locks, reprogramaciones, consumo y errores.</p>';

        $auto_url=add_query_arg('auto','1',self::tab_url('learning-log'));
        echo '<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
        echo '<a class="button button-primary" href="'.esc_url(self::tab_url('learning-log')).'">Actualizar ahora</a>';
        echo '<a class="button '.esc_attr($auto?'button-primary':'').'" href="'.esc_url($auto?self::tab_url('learning-log'):$auto_url).'">'.esc_html($auto?'Detener autoactualización':'Autoactualizar cada 10 s').'</a>';
        echo '<a class="button" href="'.esc_url(TCD_Learning_Log::download_url()).'">Descargar TXT</a>';
        echo '<a class="button" href="'.esc_url(TCD_Learning_Log::clear_url()).'" onclick="return confirm(\'¿Vaciar el log de aprendizaje? El aprendizaje y el conocimiento no se modifican.\')">Vaciar log</a>';
        echo '</p>';

        echo '<textarea id="tcd-learning-log" readonly spellcheck="false" style="width:100%;height:68vh;min-height:480px;background:#111;color:#e6e6e6;font:12px/1.45 Consolas,Monaco,monospace;white-space:pre;overflow:auto;padding:12px">'.esc_textarea($log).'</textarea>';
        echo '<p class="description">El log es diagnóstico, no una copia del conocimiento. Los materiales completos y las fotos fijas siguen estando en los exports JSON de auditoría.</p>';
        echo '<script>(function(){var e=document.getElementById("tcd-learning-log");if(e)e.scrollTop=e.scrollHeight;'.($auto?'window.setTimeout(function(){window.location.reload();},10000);':''). '})();</script>';
    }

    private static function log_card($title,$value,$description=''){
        echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px">';
        echo '<div style="font-size:12px;color:#50575e">'.esc_html($title).'</div>';
        echo '<div style="font-size:18px;font-weight:600;margin:4px 0">'.esc_html($value).'</div>';
        if($description)echo '<div class="description">'.esc_html($description).'</div>';
        echo '</div>';
    }

    private static function quality_report($quality){
        $report=json_decode((string)($quality['report_json']??''),true);
        return is_array($report)?$report:array();
    }

    private static function render_quality_diagnosis($lesson,$quality){
        $report=self::quality_report($quality);
        if(!$report || 'failed'!==(string)($quality['status']??''))return;

        $origins=(array)($report['failure_origins']??array());
        $failures=(array)($report['failures']??array());
        $recommendations=(array)($report['recommendations']??array());

        echo '<div style="background:#fff;border:1px solid #d63638;border-left-width:4px;padding:14px;margin:18px 0">';
        echo '<h2 style="margin-top:0">Diagnóstico de L'.esc_html((string)$lesson).'</h2>';
        echo '<p><strong>La fuente sí se aprendió.</strong> El fallo corresponde al examen de recuperación/interpretación. ';
        echo 'Se evaluaron '.esc_html(number_format_i18n(absint($report['evaluated']??0))).' casos y ';
        echo esc_html(number_format_i18n(absint($report['failed']??0))).' quedaron fuera del Top-8.</p>';

        if(in_array(absint($lesson),array(9,10),true)){
            $evaluated_version=(string)($report['evaluated_plugin_version']??'no registrada');
            $current_version=defined('TAXOCOMMERCE_DEPENDIENTE_VERSION') ? TAXOCOMMERCE_DEPENDIENTE_VERSION : 'desconocida';
            echo '<p><strong>Versión del motor que realizó el examen:</strong> '.esc_html($evaluated_version);
            echo ' · <strong>Plugin PHP cargado:</strong> '.esc_html($current_version).'.</p>';
            if(TCD_Intelligence::quality_report_is_stale($lesson,$report)){
                echo '<p><strong>Auditoría antigua o sin versión verificable:</strong> este resultado no acredita el ranking actual.';
                echo ' Actualiza el plugin en STAGING si procede y utiliza «Revisar correcciones y reevaluar».';
                echo ' No reinicies las diez lecciones.</p>';
            }
            if(10===absint($lesson)){
                $query_origins=(array)($report['query_origins']??array());
                echo '<p><strong>Regresiones L9 examinadas con preguntas reconstruidas:</strong> ';
                echo esc_html(number_format_i18n(absint($query_origins['snapshot_facts']??0)));
                echo ' · <strong>legacy sin hechos:</strong> '.esc_html(number_format_i18n(absint($query_origins['legacy_missing_facts']??0))).'.</p>';
            }
        }

        if(9===absint($lesson)){
            $origin_counts=(array)($report['query_origins']??array());
            $rebuilt=absint($origin_counts['snapshot_facts']??0);
            $legacy=absint($origin_counts['legacy_missing_facts']??0);
            echo '<p><strong>Preguntas de examen regeneradas desde la foto fija:</strong> '.esc_html(number_format_i18n($rebuilt));
            echo ' · <strong>sin hechos suficientes (legacy):</strong> '.esc_html(number_format_i18n($legacy)).'.</p>';
            echo '<p class="description">Se mantienen los datos aprendidos y el umbral del 88 %. Una pregunta genérica evalúa la familia; las necesidades con medidas o modelo evalúan la referencia concreta.</p>';
        }

        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0">';
        foreach(array(
            'datos_fuente'=>'Datos fuente',
            'ambiguedad'=>'Ambigüedad',
            'dependiente'=>'Dependiente/ranking',
            'sin_diagnostico'=>'Sin diagnóstico',
        ) as $key=>$label){
            echo '<span style="padding:6px 10px;border:1px solid #c3c4c7;background:#f6f7f7"><strong>'.esc_html($label).':</strong> '.esc_html(number_format_i18n(absint($origins[$key]??0))).'</span>';
        }
        echo '</div>';

        echo '<p><a class="button button-primary" href="'.esc_url(TCD_Intelligence::quality_audit_json_url($lesson)).'">JSON · auditoría y corrección</a> ';
        echo '<a class="button" href="'.esc_url(TCD_Intelligence::quality_csv_url($lesson)).'">CSV · fallos del examen</a> ';
        echo '<a class="button" href="'.esc_url(TCD_Intelligence::quality_data_csv_url($lesson)).'">CSV · datos a revisar</a></p>';
        echo '<p class="description">El JSON separa lo que debes corregir en WordPress, las ambigüedades y los fallos internos de Dependiente, e incluye la deuda de datos detectada en toda la lección. Los CSV son vistas tabulares para revisión o corrección masiva.</p>';

        if($recommendations){
            echo '<h3>Qué hacer</h3><ul style="list-style:disc;padding-left:22px">';
            foreach($recommendations as $recommendation)echo '<li>'.esc_html((string)$recommendation).'</li>';
            echo '</ul>';
        }

        if($failures){
            echo '<h3>Primeros casos que fallaron</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Tipo / ID</th><th>Esperado</th><th>Posición</th><th>Origen</th><th>Por qué</th><th>Qué hacer</th></tr></thead><tbody>';
            foreach(array_slice($failures,0,12) as $failure){
                $diagnosis=(array)($failure['diagnosis']??array());
                echo '<tr>';
                echo '<td><code>'.esc_html((string)($failure['source_type']??'')).':'.esc_html((string)absint($failure['source_id']??0)).'</code></td>';
                echo '<td>'.esc_html((string)($failure['title']??$failure['query']??'' )).'</td>';
                $rank=absint($failure['rank']??0);
                echo '<td>'.esc_html($rank?(string)$rank:'Fuera Top-8').'</td>';
                echo '<td><strong>'.esc_html((string)($diagnosis['origin']??'sin_diagnostico')).'</strong></td>';
                echo '<td>'.esc_html((string)($diagnosis['reason']??'')).'</td>';
                echo '<td>'.esc_html((string)($diagnosis['action']??'')).'</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            if(count($failures)>12)echo '<p class="description">Mostrando 12 de '.esc_html(number_format_i18n(count($failures))).' fallos. El CSV contiene todos.</p>';
        }
        echo '</div>';
    }

    private static function render_interpreter() {
        $state=TCD_Original_Linguista::state();
        $stats=TCD_Original_Interprete_DB::stats();
        $progress=TCD_Original_Linguista::course_progress();
        $status=(string)($state['status']??'stopped');
        echo '<h2>Intérprete original v1.2.0 · formación lingüística</h2>';
        echo '<p>Motor original: gramática, verbos, erratas, variantes, sinónimos, contexto, ambigüedad y evidencia validada. El Intérprete no busca productos ni pregunta: entrega una petición clara al Dependiente.</p>';
        echo '<p><strong>Estado:</strong> '.esc_html($status).' · <strong>Progreso:</strong> '.esc_html((string)$progress).'%';
        echo ' · <strong>Memoria activa:</strong> '.esc_html(number_format_i18n(absint($stats['active']??0)));
        echo ' · <strong>Evidencias:</strong> '.esc_html(number_format_i18n(absint($stats['evidence']??0))).'</p>';
        echo '<progress max="100" value="'.esc_attr((string)$progress).'" style="width:min(100%,700px);height:22px"></progress>';
        echo '<p>'.esc_html((string)($state['last_message']??'')).'</p>';
        if(!empty($state['last_error']))echo '<div class="notice notice-error"><p>'.esc_html((string)$state['last_error']).'</p></div>';
        echo '<p>Formación única con las fases internas y las reglas reales del Lingüista original. Trabajo por lotes en segundo plano, con avance y memoria persistente.</p>';
        foreach(array('start'=>'Iniciar / reanudar','pause'=>'Pausar','stop'=>'Detener','restart_course'=>'Reentrenar sin borrar memoria') as $cmd=>$label){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0 8px 10px 0">';
            wp_nonce_field('tcd_original_linguista_control');
            echo '<input type="hidden" name="action" value="tcd_original_linguista_control">';
            echo '<input type="hidden" name="command" value="'.esc_attr($cmd).'">';
            echo '<button class="button '.esc_attr('start'===$cmd?'button-primary':'').'" type="submit">'.esc_html($label).'</button></form>';
        }
        echo '<p><a class="button" href="'.esc_url(TCD_Original_Linguista::export_url('course')).'">Exportar evolución y evidencias JSON</a></p>';
        echo '<p><strong>M11:</strong> forma y comprueba comprensión lingüística; no suspende por no encontrar un ID de producto que el comprador nunca especificó.</p>';
    }

    public static function render(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Sin permiso');

        $tab=isset($_GET['tab']) ? sanitize_key(wp_unslash((string)$_GET['tab'])) : 'overview';
        if(!in_array($tab,array('overview','knowledge','backup','learning-log'),true))$tab='overview';

        echo '<div class="wrap"><h1>TaxoCommerce Dependiente</h1>';
        self::tabs($tab);

        if('knowledge'===$tab){
            TCD_Knowledge_Transfer::render_admin_section();
            echo '</div>';
            return;
        }
        if('backup'===$tab){
            TCD_Backup::render_admin_section();
            echo '</div>';
            return;
        }
        if('learning-log'===$tab){
            self::render_learning_log();
            echo '</div>';
            return;
        }

        $state=TCD_Academy::state();
        $syllabus=TCD_Sources::syllabus();
        $counts=array();
        foreach(TCD_Academy::counts() as $row){
            $counts[absint($row['lesson'])][sanitize_key((string)$row['status'])]=absint($row['total']);
        }

        echo '<p>Dependiente utiliza un único flujo interno: <strong>L1-L10</strong> para aprender y evaluar el catálogo, <strong>M11</strong> para construir y validar el Intérprete y <strong>M12</strong> para mantener el conocimiento actualizado. Cada L1-L10 conserva una <strong>foto fija auditable</strong>; toda la formación queda integrada en Dependiente, sin servicios auxiliares separados.</p>';

        echo '<p style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<a class="button button-secondary" href="'.esc_url(TCD_Academy::export_url(0)).'">Exportar auditoría L1–L10 · JSON</a>';
        echo '</p>';
        echo '<p class="description">Este JSON sirve para auditar el aprendizaje de las diez lecciones. El conocimiento portable y el backup se gestionan únicamente desde sus pestañas específicas para evitar accesos duplicados.</p>';

        $priority=TCD_Load::priority();
        $sample=TCD_Load::last_sample();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="padding:14px;background:#fff;border:1px solid #c3c4c7;margin:12px 0 20px">';
        wp_nonce_field('tcd_academy_control');
        echo '<input type="hidden" name="action" value="tcd_academy_control"><input type="hidden" name="tcd_action" value="priority">';
        echo '<label for="tcd-priority"><strong>Prioridad de Dependiente</strong></label>';
        echo '<div style="display:flex;align-items:center;gap:12px;max-width:620px;margin-top:8px">';
        echo '<span>1 · Suave</span>';
        echo '<input id="tcd-priority" name="priority" type="range" min="1" max="5" step="1" value="'.esc_attr((string)$priority).'" style="flex:1" aria-describedby="tcd-priority-help tcd-priority-value">';
        echo '<span>5 · Máxima</span>';
        echo '<output id="tcd-priority-value" for="tcd-priority"><strong>'.esc_html((string)$priority).' · '.esc_html(TCD_Load::label($priority)).'</strong></output>';
        echo '</div>';
        echo '<p id="tcd-priority-help" class="description">1 trabaja suavemente a largo plazo; 3 es el equilibrio recomendado; 5 prioriza terminar cuanto antes. Dependiente ajusta automáticamente el tamaño del lote según el coste real de sus peticiones.</p>';
        echo '<button type="submit" class="button button-primary">Guardar prioridad</button>';
        if($sample){
            $cpu=null!==($sample['cpu_percent']??null) ? number_format_i18n((float)$sample['cpu_percent'],1).' % CPU petición' : 'CPU petición no disponible';
            $memory=isset($sample['memory_peak']) ? size_format(absint($sample['memory_peak'])) : '—';
            echo '<p class="description" style="margin-top:10px">Último lote: '.esc_html(number_format_i18n(absint($sample['items']??0))).' elementos · '.esc_html($cpu).' · '.esc_html(number_format_i18n((float)($sample['elapsed']??0),2)).' s · pico '.esc_html($memory).' · siguiente lote '.esc_html(number_format_i18n(absint($sample['next_batch']??TCD_Load::batch_size()))).'.</p>';
        }
        echo '<script>(function(){var s=document.getElementById("tcd-priority"),o=document.getElementById("tcd-priority-value");if(!s||!o)return;var l={1:"Suave",2:"Baja",3:"Normal",4:"Alta",5:"Máxima"};s.addEventListener("input",function(){o.innerHTML="<strong>"+s.value+" · "+l[s.value]+"</strong>";});})();</script>';
        echo '</form>';

        echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:14px;margin-bottom:16px">';
        echo '<h2 style="margin-top:0">Estado: '.esc_html((string)$state['status']).'</h2>';
        $module=absint($state['lesson']??1);
        $current_snapshot=TCD_Academy::snapshot_for_lesson($module);
        $quality_progress=class_exists('TCD_Intelligence',false)
            ? TCD_Intelligence::quality_progress($module,absint($current_snapshot['id']??0))
            : array();
        $identity_backfill_done=absint($state['identity_backfill_done']??0);
        $identity_backfill_total=absint($state['identity_backfill_total']??0);
        $identity_backfill_active=
            2===$module
            && 'quality'===(string)($state['phase']??'')
            && $identity_backfill_total>0
            && $identity_backfill_done<$identity_backfill_total;
        echo '<p><strong>Fase:</strong> '.esc_html(self::phase_label($state)).' · <strong>Módulo:</strong> '.esc_html($module<=10?'L'.$module:'M'.$module).' / 12';
        if('snapshot'===(string)($state['phase']??'')){
            echo ' · <strong>Fuentes analizadas:</strong> '.esc_html(number_format_i18n(absint($state['snapshot_cursor']??0)));
        }elseif($identity_backfill_active){
            echo ' · <strong>Identidades preparadas:</strong> '.esc_html(number_format_i18n($identity_backfill_done)).' / '.esc_html(number_format_i18n($identity_backfill_total));
        }elseif('quality'===(string)($state['phase']??'') && $quality_progress){
            echo ' · <strong>Casos evaluados:</strong> '.esc_html(number_format_i18n(absint($quality_progress['done']??0))).' / '.esc_html(number_format_i18n(absint($quality_progress['total']??0)));
        }elseif($module<=10){
            echo ' · <strong>Elementos aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['learning_cursor']??0)));
        }
        echo ' · <strong>Aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['learned']??0))).' · <strong>No aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['unlearned']??0))).'</p>';
        echo '<p>'.esc_html((string)$state['last_message']).'</p>';

        // La tabla L1-L10 ya muestra el progreso dinámico de la lección activa.
        // Evitamos duplicar aquí una segunda barra; mantenemos solo la
        // autoactualización para que la fila inferior avance en tiempo real.
        if(
            'quality'===(string)($state['phase']??'')
            && ($quality_progress || $identity_backfill_active)
            && 'running'===(string)($state['status']??'')
        ){
            echo '<script>window.setTimeout(function(){window.location.reload();},4000);</script>';
        }

        if(!empty($state['last_error']))echo '<div class="notice notice-error inline"><p>'.esc_html((string)$state['last_error']).'</p></div>';
        $quality_failed='quality_failed'===(string)($current_snapshot['status']??'');
        if('running'!==(string)$state['status']){
            self::control_button(
                'start',
                $quality_failed?'Revisar correcciones y reevaluar':'Iniciar o reanudar',
                'button button-primary'
            );
        }
        self::control_button('stop','Detener aprendizaje');
        if('running'!==(string)$state['status'])self::control_button('restart','Reiniciar aprendizaje completo');
        echo '<p class="description"><strong>Detener</strong> conserva la foto fija y el progreso. Si una lección falla calidad, <strong>Revisar correcciones y reevaluar</strong> vuelve a leer únicamente los casos fallidos y repite el examen, sin reiniciar L1-L10. <strong>Reiniciar</strong> borra únicamente las tablas de aprendizaje propias de Dependiente y crea un run nuevo; nunca modifica productos, categorías, FAQs o Vocabulary.</p>';
        echo '</div>';

        echo '<h2>Las diez lecciones</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Lección</th><th>Qué aprende</th><th>Foto fija / progreso</th><th>Aprendidos</th><th>No aprendidos</th><th>Calidad</th><th>Auditoría</th></tr></thead><tbody>';
        foreach($syllabus as $no=>$lesson){
            $snapshot=TCD_Academy::snapshot_for_lesson($no);
            $status=$snapshot?(string)$snapshot['status']:'Pendiente';
            $sources=$snapshot?absint($snapshot['source_count']):0;
            $signature=$snapshot?(string)$snapshot['source_signature']:'';
            $planned=TCD_Sources::source_total($no);
            $is_current=$no===absint($state['lesson']);
            $building=$is_current && 'snapshot'===(string)($state['phase']??'');
            $identity_backfill_in_progress=$is_current && $identity_backfill_active;
            $quality_in_progress=$is_current && 'quality'===(string)($state['phase']??'') && !empty($quality_progress) && !$identity_backfill_in_progress;
            if($identity_backfill_in_progress){
                $progress_value=$identity_backfill_done;
                $progress_total=$identity_backfill_total;
                $progress_text='identidades L2 preparadas desde la foto fija';
                $status='preparando identidad';
            }elseif($quality_in_progress){
                $progress_value=absint($quality_progress['done']??0);
                $progress_total=absint($quality_progress['total']??0);
                $progress_text='casos evaluados del examen de calidad';
                $status='reevaluando';
            }elseif($building || !$sources){
                $progress_value=$is_current?absint($state['snapshot_cursor']??0):0;
                $progress_total=$planned;
                $progress_text='fuentes leídas para la foto fija';
            }else{
                $progress_value=absint($snapshot['learned_count']??0)+absint($snapshot['unlearned_count']??0);
                if('completed'===$status)$progress_value=$sources;
                $progress_total=$sources;
                $progress_text='elementos procesados de la foto fija';
            }
            $percent=$progress_total>0 ? min(100,round(($progress_value/$progress_total)*100)) : 0;
            echo '<tr>';
            echo '<td><strong>L'.esc_html((string)$no).'</strong> '.esc_html((string)$lesson['name']).'<div class="description"><code>'.esc_html((string)$lesson['key']).'</code></div></td>';
            echo '<td>'.esc_html((string)$lesson['objective']).'<div class="description">Fuente: '.esc_html((string)$lesson['source']).'</div></td>';
            echo '<td><strong>'.esc_html($status).'</strong>';
            echo '<div>'.esc_html(number_format_i18n($progress_value)).' / '.esc_html(number_format_i18n($progress_total)).' '.esc_html($progress_text).' · '.esc_html((string)$percent).'%</div>';
            echo '<div style="max-width:220px;height:8px;background:#dcdcde;margin:5px 0"><span style="display:block;height:8px;background:currentColor;width:'.esc_attr((string)$percent).'%"></span></div>';
            if($snapshot && $signature)echo '<div class="description">Foto sellada: <code>'.esc_html(substr($signature,0,12)).'…</code></div>';
            if($sources>0 && $planned>0 && $planned!==$sources){
                echo '<div class="description">Foto fija: '.esc_html(number_format_i18n($sources)).' · fuente actual: '.esc_html(number_format_i18n($planned)).'. La foto permanece congelada durante esta lección.</div>';
            }
            echo '</td>';
            echo '<td>'.esc_html(number_format_i18n(absint($counts[$no]['learned']??0))).'</td>';
            echo '<td>'.esc_html(number_format_i18n(absint($counts[$no]['unlearned']??0))).'</td>';
            $quality=TCD_Intelligence::quality_for_lesson($no);
            echo '<td>';
            if($quality){
                $quality_report=self::quality_report($quality);
                $passed='passed'===(string)($quality['status']??'');
                if($identity_backfill_in_progress){
                    $identity_percent=$identity_backfill_total>0 ? (100*$identity_backfill_done/$identity_backfill_total) : 0;
                    echo '<strong>PREPARANDO IDENTIDAD · '.esc_html(number_format_i18n($identity_percent,1)).'%</strong>';
                    echo '<div class="description">Completando la memoria de identidad de la foto fija antes de repetir el examen.</div>';
                }elseif($quality_in_progress){
                    echo '<strong>REEVALUANDO · '.esc_html(number_format_i18n((float)($quality_progress['percent']??0),1)).'%</strong>';
                    echo '<div class="description">Resultado anterior debajo; se sustituirá al completar 180/180.</div>';
                }
                echo '<strong>'.esc_html($passed?'OK':'NO').'</strong>';
                echo '<div>'.esc_html(number_format_i18n(100*(float)($quality['pass_any_ratio']??0),1)).'% Top8 · mínimo '.esc_html(number_format_i18n(100*(float)($quality['threshold']??0),1)).'%</div>';
                echo '<div class="description">Top1 '.esc_html(number_format_i18n(absint($quality['pass_top1']??0))).' · Top3 '.esc_html(number_format_i18n(absint($quality['pass_top3']??0))).' · Top8 '.esc_html(number_format_i18n(absint($quality['pass_top8']??0))).' · fallos '.esc_html(number_format_i18n(absint($quality['failed']??0))).' · evaluadas '.esc_html(number_format_i18n(absint($quality['evaluated']??0))).'</div>';
                if(!$passed){
                    $origins=(array)($quality_report['failure_origins']??array());
                    echo '<div class="description">Datos '.esc_html(number_format_i18n(absint($origins['datos_fuente']??0))).' · ambigüedad '.esc_html(number_format_i18n(absint($origins['ambiguedad']??0))).' · Dependiente '.esc_html(number_format_i18n(absint($origins['dependiente']??0))).'</div>';
                }
                echo '<div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap">';
                echo '<a class="button button-small" href="'.esc_url(TCD_Intelligence::quality_audit_json_url($no)).'">JSON auditoría</a>';
                echo '<a class="button button-small" href="'.esc_url(TCD_Intelligence::quality_csv_url($no)).'">CSV examen</a>';
                echo '</div>';
            }else echo '—';
            echo '</td>';
            echo '<td>';
            if($snapshot){
                echo '<a class="button button-small" href="'.esc_url(TCD_Academy::export_url($no)).'">JSON aprendizaje</a> ';
                if($quality)echo '<a class="button button-small" href="'.esc_url(TCD_Intelligence::quality_audit_json_url($no)).'">JSON auditoría</a> ';
                echo '<a class="button button-small" href="'.esc_url(TCD_Intelligence::quality_data_csv_url($no)).'">CSV datos</a>';
            }else echo '—';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        $current_quality=TCD_Intelligence::quality_for_lesson(absint($state['lesson']??0));
        if($current_quality && 'failed'===(string)($current_quality['status']??'')){
            self::render_quality_diagnosis(absint($state['lesson']??0),$current_quality);
        }

        $interpreter=TCD_Intelligence::interpreter_state();
        $maintenance=TCD_Maintenance::state();
        $feedback=class_exists('TCD_Feedback',false)?TCD_Feedback::stats():array();
        $linguista=TCD_Original_Linguista::state();
        $lexicon=TCD_Original_Interprete_DB::stats();
        echo '<h2>M11 · Intérprete original y M12 · Actualización</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Módulo</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>';
        echo '<tr><td><strong>M11 · Lingüista original v1.2.0</strong></td>';
        echo '<td>'.esc_html((string)($linguista['status']??'Pendiente')).'</td><td>';
        echo esc_html((string)TCD_Original_Linguista::course_progress()).'% curso lingüístico · ';
        echo esc_html(number_format_i18n(absint($lexicon['active']??0))).' expresiones activas · ';
        echo esc_html(number_format_i18n(absint($lexicon['evidence']??0))).' evidencias';
        echo '<p class="description">El examen antiguo de 180 IDs era una prueba de búsqueda, no de interpretación lingüística, y ya no bloquea el curso.</p></td></tr>';
        echo '<tr><td colspan="3"><details><summary><strong>L11 · Fases de comprensión lingüística y evolución</strong></summary>';
        echo '<p>Formación lingüística dentro de Dependiente. Prioridad de CPU compartida con L1–L10.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Fase</th><th>Estado</th><th>Progreso</th><th>Aprendidas</th><th>Rechazadas</th></tr></thead><tbody>';
        foreach(TCD_Original_Linguista::lesson_statuses() as $phase){
            echo '<tr><td>'.esc_html((string)($phase['title']??$phase['key']??'')).'</td><td>'.esc_html((string)($phase['status']??'')).'</td><td>'.esc_html((string)absint($phase['progress']??0)).'%</td><td>'.esc_html(number_format_i18n(absint($phase['learned']??0))).'</td><td>'.esc_html(number_format_i18n(absint($phase['rejected']??0))).'</td></tr>';
        }
        echo '</tbody></table><p><a class="button" href="'.esc_url(TCD_Original_Linguista::export_url('course')).'">Exportar evolución lingüística JSON</a></p></details></td></tr>';
        echo '<tr><td><strong>M12 · Actualización continua</strong></td><td>'.esc_html((string)($maintenance['status']??'Pendiente')).'</td><td>';
        echo 'Productos actualizados: '.esc_html(number_format_i18n(absint($maintenance['products_updated']??0))).' · ';
        echo 'categorías: '.esc_html(number_format_i18n(absint($maintenance['categories_updated']??0))).' · ';
        echo 'editorial: '.esc_html(number_format_i18n(absint($maintenance['editorial_updated']??0))).' · ';
        echo 'FAQs: '.esc_html(number_format_i18n(absint($maintenance['faqs_updated']??0))).' · ';
        echo 'Vocabulary: '.esc_html(number_format_i18n(absint($maintenance['vocabulary_updated']??0)));
        if(!empty($maintenance['last_run']))echo '<div class="description">Última revisión: '.esc_html((string)$maintenance['last_run']).'</div>';
        echo '</td></tr>';

        echo '<tr><td><strong>M12 · Feedback de clientes</strong></td><td>';
        echo esc_html(number_format_i18n(absint($feedback['active']??0))).' asociaciones activas';
        echo '</td><td>';
        echo 'Consultas: '.esc_html(number_format_i18n(absint($feedback['searches']??0))).' · ';
        echo 'clics: '.esc_html(number_format_i18n(absint($feedback['clicks']??0))).' · ';
        echo 'aclaraciones: '.esc_html(number_format_i18n(absint($feedback['clarifications']??0))).' · ';
        echo 'staged: '.esc_html(number_format_i18n(absint($feedback['staged']??0))).' · ';
        echo 'validadas: '.esc_html(number_format_i18n(absint($feedback['validated']??0))).' · ';
        echo 'activas producto: '.esc_html(number_format_i18n(absint($feedback['active_products']??0))).' · ';
        echo 'activas semánticas: '.esc_html(number_format_i18n(absint($feedback['active_semantic']??0)));
        echo '<div class="description">Un clic aislado no enseña una regla. Las aclaraciones pesan más, pero también necesitan repetición antes de activarse.</div>';
        echo '</td></tr></tbody></table>';

        echo '<h2>Asistente e integración</h2>';
        echo '<p>El shortcode canónico del plugin moderno es <code>[taxocommerce_dependiente_moderno]</code>. En STAGING, <code>[taxocommerce_dependiente]</code> también apunta al moderno para facilitar pruebas; en PRO, si el Dependiente antiguo está cargado, ese alias queda reservado al plugin antiguo.</p>';
        echo '<p><strong>PHP para una plantilla:</strong></p>';
        echo '<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;overflow:auto"><code>'.esc_html("<?php\nif ( shortcode_exists( 'taxocommerce_dependiente_moderno' ) ) {\n    echo do_shortcode( '[taxocommerce_dependiente_moderno]' );\n}\n?>").'</code></pre>';
        echo '<p>El Intérprete moderno usa reglas semánticas, Vocabulary, memoria L9 ponderada, feedback validado de clics y aclaraciones, y catálogo publicado. Cuando la confianza es insuficiente puede hacer hasta dos preguntas cerradas antes de recomendar.</p>';
        echo '<p>L6 utiliza directamente las FAQs canónicas de TaxoCommerce FAQs (<code>seo_faq</code>) y su categoría/propietario; no exige un CPT FAQ artificial.</p>';
        echo '</div>';
    }
}
