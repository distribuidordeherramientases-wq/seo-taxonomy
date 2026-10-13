<?php
/**
 * Mercado: piloto de lectura unificada sin reemplazar los servicios heredados.
 *
 * No crea tablas, no lanza workers y no solicita datos a APIs externas.
 * Las tres vistas leen exclusivamente estados ya persistidos. Las operaciones
 * y los informes completos siguen en las rutas originales.
 */
defined('ABSPATH') || exit;

final class SEO_Mercado_Admin {
    const PAGE = 'seo-mercado';

    public static function register_page() {
        add_submenu_page(
            'seo-system',
            'Mercado',
            'Mercado',
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render')
        );
    }

    private static function url($args) {
        return add_query_arg((array) $args, admin_url('admin.php'));
    }

    private static function link($label, $args, $primary = false) {
        echo '<a class="button ' . ($primary ? 'button-primary' : '') . '" href="' . esc_url(self::url($args)) . '">' . esc_html($label) . '</a> ';
    }

    private static function metric($label, $value, $note = '') {
        echo '<div class="seo-mercado-metric"><span>' . esc_html($label) . '</span>';
        echo '<strong>' . esc_html((string) $value) . '</strong>';
        if ($note !== '') echo '<small>' . esc_html($note) . '</small>';
        echo '</div>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para consultar Mercado.', 'seo-taxonomy'));
        }

        $views = array(
            'donde_estamos' => array('label'=>'Dónde estamos', 'service'=>'Analista'),
            'como_mejoramos' => array('label'=>'Cómo mejoramos', 'service'=>'Ingeniero'),
            'hacia_donde_vamos' => array('label'=>'Hacia dónde vamos', 'service'=>'Ojeador'),
        );
        $view = isset($_GET['mercado_view']) ? sanitize_key(wp_unslash($_GET['mercado_view'])) : 'donde_estamos';
        if (!isset($views[$view])) $view = 'donde_estamos';
        ?>
        <div class="wrap seo-mercado">
            <style>
                .seo-mercado .seo-mercado-intro{max-width:880px;line-height:1.55}
                .seo-mercado .seo-mercado-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-top:18px;padding:20px}
                .seo-mercado .seo-mercado-panel h2{margin-top:0}
                .seo-mercado .seo-mercado-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin:18px 0}
                .seo-mercado .seo-mercado-metric{border:1px solid #dcdcde;border-radius:6px;padding:14px;min-width:0}
                .seo-mercado .seo-mercado-metric span,.seo-mercado .seo-mercado-metric small{display:block;color:#50575e}
                .seo-mercado .seo-mercado-metric strong{display:block;font-size:24px;line-height:1.3;margin:4px 0;overflow-wrap:anywhere}
                .seo-mercado .seo-mercado-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
                .seo-mercado .seo-mercado-table{overflow-x:auto;margin-top:18px}
                .seo-mercado .seo-mercado-note{color:#50575e;font-size:13px}
                .seo-mercado .nav-tab-wrapper{display:flex;gap:4px;flex-wrap:wrap}
                .seo-mercado .seo-mercado-status{margin:12px 0;padding:10px 14px;border-left:4px solid #72aee6;background:#f0f6fc}
                @media(max-width:782px){.seo-mercado .seo-mercado-panel{padding:14px}.seo-mercado .seo-mercado-metrics{grid-template-columns:repeat(auto-fit,minmax(135px,1fr))}}
            </style>
            <h1>Mercado</h1>
            <p class="seo-mercado-intro">Una visión sencilla de la tienda: <strong>dónde estamos</strong> según Google,
                <strong>cómo mejoramos</strong> con conocimiento técnico y <strong>hacia dónde vamos</strong> según el mercado.</p>
            <div class="seo-mercado-status">
                <strong>Versión piloto en paralelo.</strong>
                Las pantallas anteriores siguen disponibles. Esta vista sólo lee información guardada; no inicia búsquedas,
                sincronizaciones, investigaciones ni propuestas editoriales.
            </div>
            <nav class="nav-tab-wrapper" aria-label="Secciones de Mercado">
                <?php foreach ($views as $key => $item) : ?>
                    <a class="nav-tab <?php echo $view === $key ? 'nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url(self::url(array('page'=>self::PAGE, 'mercado_view'=>$key))); ?>"
                       <?php if ($view === $key) echo 'aria-current="page"'; ?>>
                        <?php echo esc_html($item['label']); ?>
                        <small>(<?php echo esc_html($item['service']); ?>)</small>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php
            if ($view === 'como_mejoramos') {
                self::render_ingeniero();
            } elseif ($view === 'hacia_donde_vamos') {
                self::render_ojeador();
            } else {
                self::render_analista();
            }
            ?>
        </div>
        <?php
    }

    private static function render_analista() {
        // Sólo la cola persistida; NO regenerar informes ni llamar a Search Console/GA4.
        $history = get_option('seo_analista_task_history_381', array());
        $history = is_array($history) ? $history : array();
        $active = 0;
        $now = 0;
        $later = 0;
        $top = array();
        foreach ($history as $entry) {
            if (!is_array($entry) || in_array(sanitize_key((string)($entry['status'] ?? '')), array('executed','dismissed'), true)) continue;
            $task = (array)($entry['last_task'] ?? array());
            if (!$task) continue;
            $bucket = strtoupper(str_replace(' ', '_', (string)($task['final_bucket'] ?? '')));
            if ($bucket === 'SIN_ACCION') continue;
            $active++;
            if ($bucket === 'HACER_AHORA') $now++;
            if ($bucket === 'HACER_DESPUES') $later++;
            $topic = sanitize_text_field((string)($task['query'] ?? ''));
            if ($topic !== '' && $bucket === 'HACER_AHORA') {
                $top[] = array(
                    'topic'=>$topic,
                    'score'=>absint($task['priority_score'] ?? 0),
                    'action'=>sanitize_text_field((string)($task['task_decision']['intervention_type'] ?? $task['action_type'] ?? 'Revisar')),
                );
            }
        }
        usort($top, static function($a, $b) { return $b['score'] <=> $a['score']; });
        ?>
        <section class="seo-mercado-panel">
            <h2>¿Dónde estamos? · Analista</h2>
            <p>Consulta las señales ya procesadas de Google y las prioridades obtenidas. Las cifras siguientes son tareas guardadas,
                <strong>no</strong> estadísticas de visitas en tiempo real.</p>
            <div class="seo-mercado-metrics">
                <?php
                self::metric('Tareas activas registradas', number_format_i18n($active));
                self::metric('Hacer ahora', number_format_i18n($now));
                self::metric('Hacer después', number_format_i18n($later));
                ?>
            </div>
            <?php if ($top) : ?>
                <h3>Prioridades inmediatas</h3>
                <div class="seo-mercado-table">
                    <table class="widefat striped">
                        <thead><tr><th>Consulta o tema</th><th>Acción</th><th>Prioridad</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($top, 0, 5) as $row) : ?>
                            <tr><td><?php echo esc_html($row['topic']); ?></td>
                                <td><?php echo esc_html($row['action']); ?></td>
                                <td><?php echo absint($row['score']); ?>/100</td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="seo-mercado-note">No hay tareas «Hacer ahora» en el último estado guardado. Consulta el informe completo para
                    revisar la cobertura, los datos disponibles y las tareas en otras situaciones.</p>
            <?php endif; ?>
            <div class="seo-mercado-actions">
                <?php
                self::link('Ver informe completo de Analista', array('page'=>'seo-reports', 'tab'=>'analista', 'analista_view'=>'donde_estamos'), true);
                self::link('Ver plan de acción', array('page'=>'seo-reports','tab'=>'analista','analista_view'=>'hacia_donde_vamos'));
                self::link('Ver datos de Google', array('page'=>'seo-reports','tab'=>'google_intelligence'));
                ?>
            </div>
        </section>
        <?php
    }

    private static function render_ingeniero() {
        $state = get_option('seo_ingeniero_state_v1', array());
        $state = is_array($state) ? $state : array();
        $editorial = get_option('seo_ingeniero_editorial_refresh_state', array());
        $editorial = is_array($editorial) ? $editorial : array();
        $queue = isset($state['queue']) && is_array($state['queue']) ? count($state['queue']) : 0;
        $status = sanitize_key((string)($state['status'] ?? ''));
        $labels = array('running'=>'En marcha', 'prepared'=>'Preparado', 'stopped'=>'Detenido', 'completed'=>'Finalizado', 'paused'=>'Pausado', 'error'=>'Error');
        $status = $status === '' ? 'Sin estado guardado' : ($labels[$status] ?? $status);
        ?>
        <section class="seo-mercado-panel">
            <h2>¿Cómo mejoramos? · Ingeniero</h2>
            <p>Ingeniero investiga manuales, fabricantes, compatibilidades y conocimiento práctico para alimentar las propuestas de contenidos.
                El resumen corresponde al último estado guardado, sin iniciar una nueva investigación.</p>
            <div class="seo-mercado-metrics">
                <?php
                self::metric('Estado de investigación', $status);
                self::metric('Categorías en la cola guardada', number_format_i18n($queue));
                self::metric('Categorías procesadas en la ejecución', number_format_i18n(absint($state['processed'] ?? 0)));
                self::metric('Dossiers revisados en el ciclo editorial', number_format_i18n(absint($editorial['processed'] ?? 0)), 'No representa el total histórico.');
                ?>
            </div>
            <p class="seo-mercado-note">El material técnico y los dossiers siguen en Ingeniero. Aceptar propuestas allí puede crear
                borradores WordPress: ese flujo antiguo no se ha cambiado en esta fase.</p>
            <div class="seo-mercado-actions">
                <?php
                self::link('Ver investigación', array('page'=>'seo-ingeniero','tab'=>'research'), true);
                self::link('Ver propuestas técnicas', array('page'=>'seo-ingeniero','tab'=>'editorial'));
                self::link('Ver landings técnicas', array('page'=>'seo-ingeniero','tab'=>'landings'));
                ?>
            </div>
        </section>
        <?php
    }

    private static function render_ojeador() {
        // Merchant guarda snapshots locales; nunca solicitar Reports API al abrir Mercado.
        $snapshot = get_option('seo_ojeador_merchant_snapshots_v1', array());
        $snapshot = is_array($snapshot) ? $snapshot : array();
        $prices = (array)($snapshot['prices']['rows'] ?? array());
        $popular = (array)($snapshot['popular']['rows'] ?? array());
        $mode = sanitize_key((string)get_option('seo_ojeador_market_source_v1', 'merchant_only'));
        $mode = $mode === 'legacy' ? 'Modo tradicional' : 'Merchant primero';
        $last_prices = sanitize_text_field((string)($snapshot['prices']['updated_at'] ?? ''));
        $last_popular = sanitize_text_field((string)($snapshot['popular']['updated_at'] ?? ''));
        $account = (string)get_option('seo_ojeador_merchant_account_v1', '');
        $configured = preg_match('/^[0-9]{5,20}$/', $account) ? 'ID configurado' : 'Sin ID configurado';
        ?>
        <section class="seo-mercado-panel">
            <h2>¿Hacia dónde vamos? · Ojeador</h2>
            <p>Observa el entorno comercial: referencias de precio, productos populares y posibles huecos de catálogo.
                Google Merchant es la fuente preferente cuando la cuenta tiene acceso a sus informes.</p>
            <div class="seo-mercado-metrics">
                <?php
                self::metric('Fuente configurada', $mode);
                self::metric('Cuenta Merchant', $configured);
                self::metric('Filas de referencia de precio', number_format_i18n(count($prices)));
                self::metric('Filas de productos populares', number_format_i18n(count($popular)));
                ?>
            </div>
            <p class="seo-mercado-note">Última sincronización de precios: <?php echo esc_html($last_prices ?: 'Sin datos'); ?>.
                Productos populares: <?php echo esc_html($last_popular ?: 'Sin datos'); ?>.
                Los benchmarks Merchant son agregados: no equivalen a precios individuales de comercios.</p>
            <div class="seo-mercado-actions">
                <?php
                self::link('Ver Google Merchant', array('page'=>'seo-ojeador','ojeador_tab'=>'merchant'), true);
                self::link('Ver recomendaciones comerciales', array('page'=>'seo-ojeador','ojeador_tab'=>'analysis'));
                self::link('Ver posición de precios', array('page'=>'seo-ojeador','ojeador_tab'=>'stars'));
                ?>
            </div>
        </section>
        <?php
    }
}

add_action('admin_menu', array('SEO_Mercado_Admin', 'register_page'), 20);
