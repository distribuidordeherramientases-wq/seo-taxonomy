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
    public static function render(){
        if(!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))wp_die('Sin permiso');
        $state=TCD_Academy::state();
        $syllabus=TCD_Sources::syllabus();
        $counts=array();
        foreach(TCD_Academy::counts() as $row){
            $counts[absint($row['lesson'])][$row['status']]=absint($row['total']);
        }
        echo '<div class="wrap"><h1>TaxoCommerce Dependiente · Academia</h1>';
        echo '<p>Plugin independiente. Solo las diez lecciones iniciales; no hay aprendizaje continuo ni worker del sistema antiguo.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="padding:14px;background:#fff;border:1px solid #c3c4c7;margin:12px 0 20px">';
        wp_nonce_field('tcd_academy_control');
        echo '<input type="hidden" name="action" value="tcd_academy_control"><input type="hidden" name="tcd_action" value="cpu_limit">';
        echo '<label for="tcd-cpu-limit"><strong>Límite máximo de CPU para Dependiente (%)</strong></label> ';
        echo '<input id="tcd-cpu-limit" name="cpu_limit" type="number" min="1" max="100" step="1" required style="width:85px" value="'.esc_attr((string)TCD_CPU::limit()).'"> ';
        echo '<button type="submit" class="button button-primary">Guardar límite</button>';
        $sample=(array)($state['cpu_last']??array());
        echo '<p class="description">CPU: '.(isset($sample['percent']) && null!==$sample['percent'] ? esc_html((string)$sample['percent']).' %' : 'sin medición').'; fuente: '.esc_html((string)($sample['source']??'pendiente')).'. '.esc_html((string)($sample['reason']??'')).'</p>';
        echo '<p class="description">El control de /proc/stat mide la CPU total del servidor, no necesariamente tu cuota de hosting. Si no existe una métrica fiable, Dependiente pausa y vuelve a comprobar.</p></form>';
        echo '<h2>Estado: '.esc_html((string)$state['status']).'</h2>';
        echo '<p>Lección: '.esc_html((string)$state['lesson']).' / 10 · Cursor: '.esc_html((string)$state['cursor']).' · Aprendidos: '.esc_html((string)$state['learned']).' · No aprendidos: '.esc_html((string)$state['unlearned']).'</p>';
        echo '<p>'.esc_html((string)$state['last_message']).'</p>';
        if($state['last_error'])echo '<div class="notice notice-error"><p>'.esc_html((string)$state['last_error']).'</p></div>';
        if('running'!==$state['status'])self::control_button('start','Iniciar o reanudar','button button-primary');
        self::control_button('stop','Detener aprendizaje');
        if('running'!==$state['status'])self::control_button('restart','Reiniciar las diez lecciones');
        echo '<p><strong>El botón detiene los siguientes lotes.</strong> Una petición PHP ya iniciada debe finalizar para que se detenga su operación actual.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Lección</th><th>Objetivo</th><th>Aprendidos</th><th>No aprendidos</th></tr></thead><tbody>';
        foreach($syllabus as $no=>$lesson){
            echo '<tr><td><strong>L'.esc_html((string)$no).'</strong> '.esc_html($lesson['name']).'</td><td>'.esc_html($lesson['objective']).'</td><td>'.esc_html((string)($counts[$no]['learned']??0)).'</td><td>'.esc_html((string)($counts[$no]['unlearned']??0)).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Registro técnico</h2><p>Últimos eventos, conservados en WordPress. Los fallos de fuentes no se confunden con aprendizaje correcto.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Fecha (UTC)</th><th>Evento</th><th>Detalles</th></tr></thead><tbody>';
        foreach(array_reverse(array_slice(TCD_Academy::logs(),-30)) as $entry){
            echo '<tr><td>'.esc_html((string)$entry['at']).'</td><td>'.esc_html((string)$entry['event']).'</td><td><code>'.esc_html(wp_json_encode($entry['context'])).'</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Asistente</h2><p>Inserta el shortcode <code>[taxocommerce_dependiente]</code> para ofrecer búsqueda de catálogo con fuentes.</p>';
        echo '<p>La lección 6 necesita un tipo de contenido FAQ nativo y una asociación verificable con su producto o categoría. No se consulta la tabla antigua seo_faq.</p>';
        echo '</div>';
    }
}
