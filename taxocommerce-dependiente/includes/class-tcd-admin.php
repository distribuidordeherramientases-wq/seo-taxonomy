<?php
defined('ABSPATH') || exit;

final class TCD_Admin {
    public static function boot(){
        add_action('admin_menu',array(__CLASS__,'menu'),30);
    }

    public static function menu(){
        $parent=class_exists('TaxoCommerce_Core')?'taxocommerce-core':'tools.php';
        add_submenu_page($parent,'TaxoCommerce Dependiente','Dependiente','manage_woocommerce',
            'taxocommerce-dependiente',array(__CLASS__,'render'));
    }

    private static function control_button($action,$label,$class='button'){
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('tcd_academy_control');
        echo '<input type="hidden" name="action" value="tcd_academy_control">';
        echo '<input type="hidden" name="tcd_action" value="'.esc_attr($action).'">';
        echo '<button type="submit" class="'.esc_attr($class).'">'.esc_html($label).'</button></form>';
    }

    private static function phase_label($state){
        if('completed'===(string)($state['status']??''))return 'Completado';
        if('snapshot'===(string)($state['phase']??''))return 'Analizando / creando foto fija';
        return 'Aprendiendo la foto fija';
    }

    public static function render(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Sin permiso');

        $state=TCD_Academy::state();
        $syllabus=TCD_Sources::syllabus();
        $counts=array();
        foreach(TCD_Academy::counts() as $row){
            $counts[absint($row['lesson'])][sanitize_key((string)$row['status'])]=absint($row['total']);
        }

        echo '<div class="wrap"><h1>TaxoCommerce Dependiente</h1>';
        echo '<p>Dependiente aprende el catálogo en diez lecciones. Cada lección se analiza primero y se congela en una <strong>foto fija auditable</strong>; después el aprendizaje se realiza exclusivamente sobre esa foto. No hay servicios separados de Academia o Entrenador.</p>';

        echo '<p><a class="button button-secondary" href="'.esc_url(TCD_Academy::export_url(0)).'">Exportar aprendizaje completo · JSON</a></p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="padding:14px;background:#fff;border:1px solid #c3c4c7;margin:12px 0 20px">';
        wp_nonce_field('tcd_academy_control');
        echo '<input type="hidden" name="action" value="tcd_academy_control"><input type="hidden" name="tcd_action" value="cpu_limit">';
        echo '<label for="tcd-cpu-limit"><strong>Límite máximo de CPU para Dependiente (%)</strong></label> ';
        echo '<input id="tcd-cpu-limit" name="cpu_limit" type="number" min="1" max="100" step="1" required style="width:85px" value="'.esc_attr((string)TCD_CPU::limit()).'"> ';
        echo '<button type="submit" class="button button-primary">Guardar límite</button>';
        $sample=(array)($state['cpu_last']??array());
        echo '<p class="description">CPU: '.(isset($sample['percent']) && null!==$sample['percent'] ? esc_html((string)$sample['percent']).' %' : 'sin medición').'; fuente: '.esc_html((string)($sample['source']??'pendiente')).'. '.esc_html((string)($sample['reason']??'')).'</p>';
        echo '<p class="description">Si no existe una métrica fiable, Dependiente pausa y vuelve a comprobar. El aprendizaje y la foto fija permanecen guardados.</p></form>';

        echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:14px;margin-bottom:16px">';
        echo '<h2 style="margin-top:0">Estado: '.esc_html((string)$state['status']).'</h2>';
        echo '<p><strong>Fase:</strong> '.esc_html(self::phase_label($state)).' · <strong>Lección:</strong> '.esc_html((string)$state['lesson']).' / 10';
        if('snapshot'===(string)($state['phase']??'')){
            echo ' · <strong>Fuentes analizadas:</strong> '.esc_html(number_format_i18n(absint($state['snapshot_cursor']??0)));
        }else{
            echo ' · <strong>Elementos aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['learning_cursor']??0)));
        }
        echo ' · <strong>Aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['learned']??0))).' · <strong>No aprendidos:</strong> '.esc_html(number_format_i18n(absint($state['unlearned']??0))).'</p>';
        echo '<p>'.esc_html((string)$state['last_message']).'</p>';
        if(!empty($state['last_error']))echo '<div class="notice notice-error inline"><p>'.esc_html((string)$state['last_error']).'</p></div>';
        if('running'!==(string)$state['status'])self::control_button('start','Iniciar o reanudar','button button-primary');
        self::control_button('stop','Detener aprendizaje');
        if('running'!==(string)$state['status'])self::control_button('restart','Reiniciar las diez lecciones');
        echo '<p class="description"><strong>Detener</strong> conserva la foto fija y el progreso. <strong>Reiniciar</strong> borra únicamente las tablas de aprendizaje propias de Dependiente y crea un run nuevo; nunca modifica productos, categorías, FAQs o Vocabulary.</p>';
        echo '</div>';

        echo '<h2>Las diez lecciones</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Lección</th><th>Qué aprende</th><th>Foto fija</th><th>Aprendidos</th><th>No aprendidos</th><th>Auditoría</th></tr></thead><tbody>';
        foreach($syllabus as $no=>$lesson){
            $snapshot=TCD_Academy::snapshot_for_lesson($no);
            $status=$snapshot?(string)$snapshot['status']:'Pendiente';
            $sources=$snapshot?absint($snapshot['source_count']):0;
            $signature=$snapshot?(string)$snapshot['source_signature']:'';
            echo '<tr>';
            echo '<td><strong>L'.esc_html((string)$no).'</strong> '.esc_html((string)$lesson['name']).'<div class="description"><code>'.esc_html((string)$lesson['key']).'</code></div></td>';
            echo '<td>'.esc_html((string)$lesson['objective']).'<div class="description">Fuente: '.esc_html((string)$lesson['source']).'</div></td>';
            echo '<td><strong>'.esc_html($status).'</strong>';
            if($snapshot){
                echo '<div>'.esc_html(number_format_i18n($sources)).' fuentes</div>';
                if($signature)echo '<div class="description"><code>'.esc_html(substr($signature,0,12)).'…</code></div>';
            }
            echo '</td>';
            echo '<td>'.esc_html(number_format_i18n(absint($counts[$no]['learned']??0))).'</td>';
            echo '<td>'.esc_html(number_format_i18n(absint($counts[$no]['unlearned']??0))).'</td>';
            echo '<td>';
            if($snapshot)echo '<a class="button button-small" href="'.esc_url(TCD_Academy::export_url($no)).'">JSON</a>';
            else echo '—';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Registro técnico</h2><p>Últimos eventos del análisis, sellado de foto fija y aprendizaje. Los errores de fuente se distinguen de un resultado no aprendido.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Fecha (UTC)</th><th>Evento</th><th>Detalles</th></tr></thead><tbody>';
        foreach(array_reverse(array_slice(TCD_Academy::logs(),-40)) as $entry){
            echo '<tr><td>'.esc_html((string)$entry['at']).'</td><td>'.esc_html((string)$entry['event']).'</td><td><code>'.esc_html(wp_json_encode($entry['context'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</code></td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Asistente</h2><p>El shortcode <code>[taxocommerce_dependiente]</code> ofrece la interfaz pública. El aprendizaje de L1-L10 alimenta el conocimiento propio del plugin y queda disponible para su motor de respuesta.</p>';
        echo '<p>L6 utiliza directamente las FAQs canónicas de TaxoCommerce FAQs (<code>seo_faq</code>) y su categoría/propietario; no exige un CPT FAQ artificial.</p>';
        echo '</div>';
    }
}
