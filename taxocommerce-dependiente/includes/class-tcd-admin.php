<?php
defined('ABSPATH') || exit;

final class TCD_Admin {
    public static function boot(){
        add_action('admin_menu',array(__CLASS__,'menu'),30);
    }

    public static function menu(){
        if (function_exists('taxocommerce_core')) {
            add_submenu_page('taxocommerce-core','TaxoCommerce Dependiente','Dependiente','manage_woocommerce',
                'taxocommerce-dependiente',array(__CLASS__,'render'));
        } else {
            add_menu_page('TaxoCommerce Dependiente','Dependiente','manage_woocommerce',
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
        return 'Aprendiendo y evaluando la foto fija';
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
        echo '</nav>';
    }

    public static function render(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Sin permiso');

        $tab=isset($_GET['tab']) ? sanitize_key(wp_unslash((string)$_GET['tab'])) : 'overview';
        if(!in_array($tab,array('overview','knowledge'),true))$tab='overview';

        echo '<div class="wrap"><h1>TaxoCommerce Dependiente</h1>';
        self::tabs($tab);

        if('knowledge'===$tab){
            TCD_Knowledge_Transfer::render_admin_section();
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
        echo '<a class="button button-secondary" href="'.esc_url(TCD_Academy::export_url(0)).'">Exportar aprendizaje completo · JSON</a>';
        echo '<a class="button" href="'.esc_url(self::tab_url('knowledge')).'">Gestionar conocimiento portable</a>';
        echo '</p>';
        echo '<p class="description">El aprendizaje completo sirve para auditar las diez lecciones. La exportación/importación de conocimiento portable está separada en su propia pestaña para que no quede oculta al final de esta pantalla.</p>';

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
        echo '<p><strong>Fase:</strong> '.esc_html(self::phase_label($state)).' · <strong>Módulo:</strong> '.esc_html($module<=10?'L'.$module:'M'.$module).' / 12';
        if('snapshot'===(string)($state['phase']??'')){
            echo ' · <strong>Fuentes analizadas:</strong> '.esc_html(number_format_i18n(absint($state['snapshot_cursor']??0)));
        }elseif($module<=10){
            echo ' · <strong>Elementos aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['learning_cursor']??0)));
        }
        echo ' · <strong>Aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['learned']??0))).' · <strong>No aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['unlearned']??0))).'</p>';
        echo '<p>'.esc_html((string)$state['last_message']).'</p>';
        if(!empty($state['last_error']))echo '<div class="notice notice-error inline"><p>'.esc_html((string)$state['last_error']).'</p></div>';
        if('running'!==(string)$state['status'])self::control_button('start','Iniciar o reanudar','button button-primary');
        self::control_button('stop','Detener aprendizaje');
        if('running'!==(string)$state['status'])self::control_button('restart','Reiniciar aprendizaje completo');
        echo '<p class="description"><strong>Detener</strong> conserva la foto fija y el progreso. <strong>Reiniciar</strong> borra únicamente las tablas de aprendizaje propias de Dependiente y crea un run nuevo; nunca modifica productos, categorías, FAQs o Vocabulary.</p>';
        echo '</div>';

        echo '<h2>Las diez lecciones</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Lección</th><th>Qué aprende</th><th>Foto fija / progreso</th><th>Aprendidos</th><th>No aprendidos</th><th>Calidad</th><th>Auditoría</th></tr></thead><tbody>';
        foreach($syllabus as $no=>$lesson){
            $snapshot=TCD_Academy::snapshot_for_lesson($no);
            $status=$snapshot?(string)$snapshot['status']:'Pendiente';
            $sources=$snapshot?absint($snapshot['source_count']):0;
            $signature=$snapshot?(string)$snapshot['source_signature']:'';
            $planned=TCD_Sources::source_total($no);
            $analysed=$snapshot ? ($sources>0 ? $sources : ($no===absint($state['lesson']) ? absint($state['snapshot_cursor']??0) : 0)) : ($no===absint($state['lesson']) ? absint($state['snapshot_cursor']??0) : 0);
            $percent=$planned>0 ? min(100,round(($analysed/$planned)*100)) : 0;
            echo '<tr>';
            echo '<td><strong>L'.esc_html((string)$no).'</strong> '.esc_html((string)$lesson['name']).'<div class="description"><code>'.esc_html((string)$lesson['key']).'</code></div></td>';
            echo '<td>'.esc_html((string)$lesson['objective']).'<div class="description">Fuente: '.esc_html((string)$lesson['source']).'</div></td>';
            echo '<td><strong>'.esc_html($status).'</strong>';
            echo '<div>'.esc_html(number_format_i18n($analysed)).' / '.esc_html(number_format_i18n($planned)).' fuentes analizadas · '.esc_html((string)$percent).'%</div>';
            echo '<div style="max-width:220px;height:8px;background:#dcdcde;margin:5px 0"><span style="display:block;height:8px;background:currentColor;width:'.esc_attr((string)$percent).'%"></span></div>';
            if($snapshot && $signature)echo '<div class="description">Foto sellada: <code>'.esc_html(substr($signature,0,12)).'…</code></div>';
            echo '</td>';
            echo '<td>'.esc_html(number_format_i18n(absint($counts[$no]['learned']??0))).'</td>';
            echo '<td>'.esc_html(number_format_i18n(absint($counts[$no]['unlearned']??0))).'</td>';
            $quality=TCD_Intelligence::quality_for_lesson($no);
            echo '<td>';
            if($quality){
                echo '<strong>'.esc_html('passed'===(string)($quality['status']??'')?'OK':'NO').'</strong>';
                echo '<div>'.esc_html(number_format_i18n(100*(float)($quality['pass_any_ratio']??0),1)).'% pass_any</div>';
                echo '<div class="description">Top1 '.esc_html(number_format_i18n(absint($quality['pass_top1']??0))).' · Top3 '.esc_html(number_format_i18n(absint($quality['pass_top3']??0))).' · evaluadas '.esc_html(number_format_i18n(absint($quality['evaluated']??0))).'</div>';
            }else echo '—';
            echo '</td>';
            echo '<td>';
            if($snapshot)echo '<a class="button button-small" href="'.esc_url(TCD_Academy::export_url($no)).'">JSON</a>';
            else echo '—';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        $interpreter=TCD_Intelligence::interpreter_state();
        $maintenance=TCD_Maintenance::state();
        echo '<h2>M11 · Intérprete y M12 · Actualización</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Módulo</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>';
        echo '<tr><td><strong>M11 · Creación / actualización del Intérprete</strong></td><td>'.esc_html((string)($interpreter['status']??'Pendiente')).'</td><td>';
        if($interpreter){
            echo esc_html(number_format_i18n(absint($interpreter['rules']??0))).' reglas · ';
            echo esc_html(number_format_i18n(absint($interpreter['l9_memory']??0))).' señales L9 · ';
            echo esc_html(number_format_i18n(100*(float)($interpreter['pass_any_ratio']??0),1)).'% regresión';
        }else echo 'Se ejecuta automáticamente después de L10.';
        echo '</td></tr>';
        echo '<tr><td><strong>M12 · Actualización continua</strong></td><td>'.esc_html((string)($maintenance['status']??'Pendiente')).'</td><td>';
        echo 'Productos actualizados: '.esc_html(number_format_i18n(absint($maintenance['products_updated']??0))).' · ';
        echo 'categorías: '.esc_html(number_format_i18n(absint($maintenance['categories_updated']??0))).' · ';
        echo 'editorial: '.esc_html(number_format_i18n(absint($maintenance['editorial_updated']??0))).' · ';
        echo 'FAQs: '.esc_html(number_format_i18n(absint($maintenance['faqs_updated']??0))).' · ';
        echo 'Vocabulary: '.esc_html(number_format_i18n(absint($maintenance['vocabulary_updated']??0)));
        if(!empty($maintenance['last_run']))echo '<div class="description">Última revisión: '.esc_html((string)$maintenance['last_run']).'</div>';
        echo '</td></tr></tbody></table>';

        echo '<h2>Registro técnico</h2><p>Últimos eventos del análisis, sellado de foto fija y aprendizaje. Los errores de fuente se distinguen de un resultado no aprendido.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Fecha (UTC)</th><th>Evento</th><th>Detalles</th></tr></thead><tbody>';
        foreach(array_reverse(array_slice(TCD_Academy::logs(),-40)) as $entry){
            echo '<tr><td>'.esc_html((string)$entry['at']).'</td><td>'.esc_html((string)$entry['event']).'</td><td><code>'.esc_html(wp_json_encode($entry['context'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</code></td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Asistente</h2><p>El shortcode <code>[taxocommerce_dependiente]</code> usa el Intérprete moderno: reglas semánticas, Vocabulary, memoria L9 ponderada y catálogo publicado. La búsqueda textual queda únicamente como red de seguridad.</p>';
        echo '<p>L6 utiliza directamente las FAQs canónicas de TaxoCommerce FAQs (<code>seo_faq</code>) y su categoría/propietario; no exige un CPT FAQ artificial.</p>';
        echo '</div>';
    }
}
