<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_V3_Lesson9_Admin {
    const PAGE = 'seo-dependiente-v3-leccion-9';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 99);
        add_action('wp_ajax_seo_dependiente_v3_l9_batch', array(__CLASS__, 'ajax_batch'));
        add_action('wp_ajax_seo_dependiente_v3_l9_reset', array(__CLASS__, 'ajax_reset'));
        add_action('wp_ajax_seo_dependiente_v3_l9_status', array(__CLASS__, 'ajax_status'));
    }

    private static function can_manage() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public static function menu() {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        add_submenu_page(
            'woocommerce',
            'Academia · Lección 9',
            'Academia · Lección 9',
            $capability,
            self::PAGE,
            array(__CLASS__, 'render')
        );
    }

    private static function verify_ajax() {
        if (!self::can_manage()) {
            wp_send_json_error(array('message' => 'No tienes permisos para ejecutar la Lección 9.'), 403);
        }
        check_ajax_referer('seo_dependiente_v3_l9', 'nonce');
    }

    public static function ajax_batch() {
        self::verify_ajax();
        $status = SEO_Dependiente_V3_Lesson9::prepare_batch(SEO_Dependiente_V3_Lesson9::BATCH_SIZE);
        if (is_wp_error($status)) {
            wp_send_json_error(array('message' => $status->get_error_message()), 400);
        }
        wp_send_json_success(array('status' => $status));
    }

    public static function ajax_reset() {
        self::verify_ajax();
        wp_send_json_success(array('status' => SEO_Dependiente_V3_Lesson9::reset()));
    }

    public static function ajax_status() {
        self::verify_ajax();
        wp_send_json_success(array(
            'status' => SEO_Dependiente_V3_Lesson9::status(),
            'preview' => SEO_Dependiente_V3_Lesson9::preview(12),
        ));
    }

    public static function render() {
        if (!self::can_manage()) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
        }
        $status = SEO_Dependiente_V3_Lesson9::status();
        $preview = SEO_Dependiente_V3_Lesson9::preview(12);
        $nonce = wp_create_nonce('seo_dependiente_v3_l9');
        ?>
        <div class="wrap" id="seo-l9-app">
            <h1>Academia · Lección 9</h1>
            <h2>Reconocimiento de necesidades y productos</h2>
            <p style="max-width:980px;font-size:14px;line-height:1.6">
                Esta lección se crea automáticamente desde el catálogo vivo. Para cada producto toma únicamente
                evidencias existentes —TIPO, ROL, aplicaciones, subtipos, categorías, etiquetas, atributos, marca,
                título y fragmentos descriptivos— y construye distintas formas en las que un cliente podría pedirlo.
                No contiene vocabulario específico de herramientas ni preguntas fijas de una tienda concreta.
            </p>

            <div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #46a52f;padding:18px;margin:18px 0;max-width:1100px">
                <div style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:16px">
                    <div><strong>Productos</strong><br><span id="l9-products"><?php echo esc_html($status['processed_products'] . ' / ' . $status['total_products']); ?></span></div>
                    <div><strong>Preguntas generadas</strong><br><span id="l9-exercises"><?php echo esc_html($status['exercises']); ?></span></div>
                    <div><strong>Señales aprendidas</strong><br><span id="l9-signals"><?php echo esc_html($status['signals']); ?></span></div>
                    <div><strong>Estado</strong><br><span id="l9-state"><?php echo $status['complete'] ? 'Completada' : ($status['processed_products'] ? 'En progreso' : 'Sin iniciar'); ?></span></div>
                </div>
                <div style="margin-top:18px;background:#f0f0f1;height:18px;border-radius:10px;overflow:hidden">
                    <div id="l9-bar" style="height:18px;background:#46a52f;width:<?php echo esc_attr($status['percent']); ?>%;transition:width .25s"></div>
                </div>
                <p><strong id="l9-percent"><?php echo esc_html($status['percent']); ?>%</strong></p>
                <p>
                    <button class="button button-primary" id="l9-start">Iniciar / continuar Lección 9</button>
                    <button class="button" id="l9-stop" disabled>Detener</button>
                    <button class="button" id="l9-reset">Reiniciar Lección 9</button>
                </p>
                <p id="l9-message" style="font-weight:600"></p>
            </div>

            <h2>Qué está aprendiendo</h2>
            <p style="max-width:980px">
                Las preguntas se guardan para auditoría y las señales se activan como memoria específica de producto.
                Una señal genérica aislada —por ejemplo una marca, una categoría amplia o el ROL— no basta para recuperar
                un producto: V3 exige evidencia acumulada para que L9 influya en el ranking.
            </p>

            <h2>Ejemplos recientes</h2>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>Producto</th><th>Tipo de ejercicio</th><th>Pregunta</th><th>Objetivo</th></tr></thead>
                <tbody id="l9-preview">
                <?php foreach ($preview as $item) : ?>
                    <tr>
                        <td>#<?php echo esc_html($item['product_id']); ?> · <?php echo esc_html($item['post_title'] ?: 'Producto'); ?></td>
                        <td><?php echo esc_html($item['exercise_type']); ?></td>
                        <td><?php echo esc_html($item['question']); ?></td>
                        <td><?php echo esc_html($item['expected_mode']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        (function(){
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            let running = false;

            const $ = id => document.getElementById(id);
            function escapeHtml(v){
                return String(v == null ? '' : v).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
            }
            function update(s){
                $('l9-products').textContent = s.processed_products + ' / ' + s.total_products;
                $('l9-exercises').textContent = s.exercises;
                $('l9-signals').textContent = s.signals;
                $('l9-state').textContent = s.complete ? 'Completada' : (s.processed_products ? 'En progreso' : 'Sin iniciar');
                $('l9-percent').textContent = s.percent + '%';
                $('l9-bar').style.width = s.percent + '%';
            }
            async function call(action){
                const body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce', nonce);
                const r = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()});
                const data = await r.json();
                if (!data || !data.success) throw new Error(data && data.data && data.data.message ? data.data.message : 'Error de Academia');
                return data.data;
            }
            async function refresh(){
                const data = await call('seo_dependiente_v3_l9_status');
                update(data.status);
                const tbody = $('l9-preview');
                tbody.innerHTML = (data.preview || []).map(item => '<tr><td>#'+escapeHtml(item.product_id)+' · '+escapeHtml(item.post_title || 'Producto')+'</td><td>'+escapeHtml(item.exercise_type)+'</td><td>'+escapeHtml(item.question)+'</td><td>'+escapeHtml(item.expected_mode)+'</td></tr>').join('');
            }
            async function loop(){
                running = true;
                $('l9-start').disabled = true;
                $('l9-stop').disabled = false;
                $('l9-message').textContent = 'Academia está generando preguntas y señales desde el catálogo…';
                try {
                    while (running) {
                        const data = await call('seo_dependiente_v3_l9_batch');
                        update(data.status);
                        if (data.status.complete) {
                            running = false;
                            $('l9-message').textContent = 'Lección 9 completada. La memoria L9 ya está disponible para Dependiente V3.';
                            await refresh();
                            break;
                        }
                    }
                } catch (e) {
                    running = false;
                    $('l9-message').textContent = e.message;
                }
                $('l9-start').disabled = false;
                $('l9-stop').disabled = true;
            }
            $('l9-start').addEventListener('click', loop);
            $('l9-stop').addEventListener('click', function(){ running = false; $('l9-message').textContent = 'Proceso detenido. Puedes continuarlo cuando quieras.'; });
            $('l9-reset').addEventListener('click', async function(){
                if (!confirm('¿Borrar la formación de la Lección 9 y empezar de nuevo?')) return;
                running = false;
                try { const data = await call('seo_dependiente_v3_l9_reset'); update(data.status); await refresh(); $('l9-message').textContent = 'Lección 9 reiniciada.'; }
                catch(e){ $('l9-message').textContent = e.message; }
            });
        })();
        </script>
        <?php
    }
}
