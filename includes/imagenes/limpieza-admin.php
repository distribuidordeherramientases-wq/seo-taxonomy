<?php
/**
 * Interfaz administrativa: Imágenes > Liberar espacio.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_images_cleanup_progress_percent')) {
    function seo_images_cleanup_progress_percent(array $state) {
        $phase = (string) ($state['phase'] ?? 'idle');

        if ($phase === 'complete') {
            return 100;
        }

        $source_total   = max(0, absint($state['source_total'] ?? 0));
        $source_indexed = max(0, absint($state['source_indexed'] ?? 0));
        $media_total    = max(0, absint($state['media_total'] ?? 0));
        $media_scanned  = max(0, absint($state['media_scanned'] ?? 0));

        // La indexación y el escaneo pesan 50 % cada uno para que el progreso
        // sea estable aunque las dos poblaciones tengan tamaños muy distintos.
        $source_pct = $source_total > 0 ? min(1, $source_indexed / $source_total) : 1;
        $media_pct  = $media_total > 0 ? min(1, $media_scanned / $media_total) : 0;

        if ($phase === 'indexing_sources') {
            return (int) round($source_pct * 50);
        }

        if ($phase === 'scanning_media') {
            return 50 + (int) round($media_pct * 50);
        }

        return 0;
    }
}

if (!function_exists('seo_images_cleanup_render_source_table')) {
    function seo_images_cleanup_render_source_table(array $sources) {
        if (empty($sources)) {
            echo '<div class="notice notice-warning inline"><p><strong>No hay fuentes externas de imágenes disponibles.</strong> Este análisis solo tiene sentido cuando existe un catálogo de URLs externas con el que comparar Media.</p></div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1000px">';
        echo '<thead><tr><th>Fuente</th><th>Tipo</th><th>Filas utilizables</th><th>Origen</th></tr></thead><tbody>';

        foreach ($sources as $source) {
            $count = seo_images_cleanup_source_count($source);
            $origin = ($source['type'] ?? '') === 'table'
                ? (string) ($source['table'] ?? '')
                : 'Callback registrado';

            echo '<tr>';
            echo '<td><strong>' . esc_html($source['label']) . '</strong><br><code>' . esc_html($source['key']) . '</code></td>';
            echo '<td>' . esc_html($source['type']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($count)) . '</td>';
            echo '<td><code>' . esc_html($origin) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

if (!function_exists('seo_images_cleanup_render_candidate_table')) {
    function seo_images_cleanup_render_candidate_table(array $rows) {
        if (empty($rows)) {
            echo '<p class="seo-images-muted">Todavía no hay coincidencias calculadas.</p>';
            return;
        }

        echo '<table class="widefat striped seo-images-cleanup-table">';
        echo '<thead><tr><th>ID</th><th>Archivo Media</th><th>Decisión</th><th>Fuente</th><th>Productos</th><th>Ejemplo externo</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $safe = !empty($row['safe_to_delete']);
            $badge = $safe ? 'seo-images-cleanup-safe' : 'seo-images-cleanup-review';

            echo '<tr>';
            echo '<td>' . absint($row['attachment_id']) . '</td>';
            echo '<td><strong>' . esc_html($row['media_filename']) . '</strong><br><small>' . esc_html($row['post_title']) . '</small></td>';
            echo '<td><span class="' . esc_attr($badge) . '">' . esc_html($row['decision']) . '</span><br><small>' . esc_html($row['match_rules']) . '</small></td>';
            echo '<td>' . esc_html($row['sources']) . '<br><small>' . esc_html($row['providers']) . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['source_products_matched']))) . '</td>';
            echo '<td style="max-width:420px;word-break:break-all"><small>' . esc_html($row['example_source_url']) . '</small></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

if (!function_exists('seo_images_render_media_cleanup_tab')) {
    /**
     * Render principal de la pestaña.
     *
     * @return void
     */
    function seo_images_render_media_cleanup_tab() {
        if (!current_user_can('manage_options')) {
            return;
        }

        seo_images_cleanup_install_tables();

        $sources      = seo_images_cleanup_external_sources();
        $state        = seo_images_cleanup_get_state();
        $stats        = seo_images_cleanup_candidate_stats();
        $delete_stats = seo_images_cleanup_delete_stats();
        $rows         = seo_images_cleanup_candidate_rows(40, false);
        $progress     = seo_images_cleanup_progress_percent($state);
        $nonce        = wp_create_nonce('seo_images_cleanup_admin');

        $phase_labels = array(
            'idle'             => 'Sin análisis iniciado',
            'indexing_sources' => 'Indexando fuentes externas',
            'scanning_media'   => 'Comparando con Media',
            'complete'         => 'Análisis completado',
            'error'            => 'Análisis detenido por error',
        );
        $phase_label = $phase_labels[$state['phase']] ?? (string) $state['phase'];
        ?>
        <style>
            .seo-images-cleanup-section{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:18px 0;max-width:1400px}
            .seo-images-cleanup-section h2{margin-top:0}
            .seo-images-cleanup-progress{height:18px;background:#f0f0f1;border-radius:999px;overflow:hidden;max-width:900px;margin:10px 0}
            .seo-images-cleanup-progress span{display:block;height:100%;background:#2271b1;min-width:0;transition:width .2s ease}
            .seo-images-cleanup-safe,.seo-images-cleanup-review{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
            .seo-images-cleanup-safe{background:#dff3e3;color:#166534}.seo-images-cleanup-review{background:#fff3cd;color:#7a4b00}
            .seo-images-cleanup-danger{border-left:4px solid #d63638;padding-left:14px}
            .seo-images-cleanup-log{background:#101517;color:#d7e0e5;padding:12px;min-height:90px;max-height:300px;overflow:auto;white-space:pre-wrap}
            .seo-images-cleanup-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0}
            .seo-images-cleanup-table td{vertical-align:top}
        </style>

        <div class="seo-images-cleanup-section">
            <h2>Fuentes externas</h2>
            <p>La limpieza compara los attachments de WordPress con catálogos externos. Un proveedor es una fuente, pero también puede registrarse un CDN, DAM o servidor de imágenes mediante el filtro <code>seo_images_cleanup_external_sources</code>.</p>
            <?php seo_images_cleanup_render_source_table($sources); ?>
        </div>

        <div class="seo-images-cleanup-section">
            <h2>1. Analizar Media</h2>
            <p>El análisis es de solo lectura sobre WordPress y las fuentes externas. Construye un índice auxiliar por lotes y no elimina archivos.</p>

            <div class="seo-images-cleanup-progress"><span id="seo-images-cleanup-progress-bar" style="width:<?php echo esc_attr((string) $progress); ?>%"></span></div>
            <p><strong id="seo-images-cleanup-phase"><?php echo esc_html($phase_label); ?></strong> · <span id="seo-images-cleanup-progress-text"><?php echo esc_html((string) $progress); ?>%</span></p>
            <p class="seo-images-muted" id="seo-images-cleanup-detail">
                Fuentes: <?php echo esc_html(number_format_i18n(absint($state['source_indexed']))); ?> / <?php echo esc_html(number_format_i18n(absint($state['source_total']))); ?> ·
                Media: <?php echo esc_html(number_format_i18n(absint($state['media_scanned']))); ?> / <?php echo esc_html(number_format_i18n(absint($state['media_total']))); ?>
            </p>

            <?php if (!empty($state['last_error'])) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($state['last_error']); ?></p></div>
            <?php endif; ?>

            <div class="seo-images-cleanup-actions">
                <button type="button" class="button button-primary" id="seo-images-cleanup-audit-start" <?php disabled(empty($sources)); ?>>
                    <?php echo ($state['status'] ?? '') === 'running' ? 'Continuar análisis' : 'Iniciar / actualizar análisis'; ?>
                </button>
                <button type="button" class="button" id="seo-images-cleanup-audit-stop" disabled>Pausar en este navegador</button>
            </div>
        </div>

        <div class="seo-images-cleanup-section">
            <h2>2. Resultado de la comparación</h2>
            <div class="seo-images-grid">
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-total"><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong><span>Coincidencias detectadas</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-safe"><?php echo esc_html(number_format_i18n($stats['safe'])); ?></strong><span>Borrado automático seguro</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-url"><?php echo esc_html(number_format_i18n($stats['BORRAR_URL_ORIGEN_EXACTA'])); ?></strong><span>URL de origen exacta</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-path"><?php echo esc_html(number_format_i18n($stats['BORRAR_RUTA_EXACTA'])); ?></strong><span>Ruta externa exacta</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-product"><?php echo esc_html(number_format_i18n($stats['BORRAR_MISMO_PRODUCTO'])); ?></strong><span>Mismo producto</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-unique"><?php echo esc_html(number_format_i18n($stats['BORRAR_RUTA_PROVEEDOR_UNICA'])); ?></strong><span>Ruta única · revisar</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-stat-shared"><?php echo esc_html(number_format_i18n($stats['REVISAR_NOMBRE_COMPARTIDO'])); ?></strong><span>Nombre compartido · revisar</span></div>
            </div>

            <p><strong>El borrado automático solo incluye:</strong> URL de origen exacta, ruta codificada exacta y mismo producto. <code>BORRAR_RUTA_PROVEEDOR_UNICA</code> y <code>REVISAR_NOMBRE_COMPARTIDO</code> quedan fuera.</p>
            <?php seo_images_cleanup_render_candidate_table($rows); ?>
        </div>

        <div class="seo-images-cleanup-section seo-images-cleanup-danger">
            <h2>3. Liberar espacio</h2>
            <p>La eliminación es irreversible. Cada attachment se elimina mediante <code>wp_delete_attachment($attachment_id, true)</code>. Después se limpian galerías de WooCommerce, thumbnails de términos e índices internos de SEO Taxonomy.</p>

            <div class="seo-images-grid">
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-del-pending"><?php echo esc_html(number_format_i18n($delete_stats['pending'])); ?></strong><span>Pendientes de borrar</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-del-deleted"><?php echo esc_html(number_format_i18n($delete_stats['deleted'])); ?></strong><span>Borrados</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-del-missing"><?php echo esc_html(number_format_i18n($delete_stats['already_missing'])); ?></strong><span>Ya no existían</span></div>
                <div class="seo-images-card seo-images-kpi"><strong id="seo-clean-del-errors"><?php echo esc_html(number_format_i18n($delete_stats['error'])); ?></strong><span>Errores</span></div>
            </div>

            <label style="display:block;margin:12px 0"><input type="checkbox" id="seo-images-cleanup-backup-confirm"> Confirmo que existe una copia de seguridad reciente de base de datos y <code>uploads</code>.</label>

            <div class="seo-images-cleanup-actions">
                <button type="button" class="button button-primary" id="seo-images-cleanup-delete-start" <?php disabled(($state['status'] ?? '') !== 'complete' || $delete_stats['pending'] < 1); ?>>Borrar coincidencias seguras</button>
                <button type="button" class="button" id="seo-images-cleanup-delete-stop" disabled>Pausar borrado</button>
                <button type="button" class="button" id="seo-images-cleanup-retry-errors" <?php disabled($delete_stats['error'] < 1); ?>>Reintentar errores</button>
            </div>
            <pre class="seo-images-cleanup-log" id="seo-images-cleanup-log">El proceso está preparado. Los lotes se ejecutan desde esta pestaña y pueden reanudarse si cierras el navegador.</pre>
        </div>

        <script>
        (function(){
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            let auditRunning = false;
            let deleteRunning = false;
            let serverState = <?php echo wp_json_encode($state); ?>;

            const $ = function(id){ return document.getElementById(id); };
            const logBox = $('seo-images-cleanup-log');

            function number(value){
                return new Intl.NumberFormat().format(Number(value || 0));
            }

            function appendLog(message){
                if (!logBox) return;
                logBox.textContent += '\n' + message;
                logBox.scrollTop = logBox.scrollHeight;
            }

            function progressPercent(state){
                if (!state) return 0;
                if (state.phase === 'complete') return 100;
                const sourceTotal = Number(state.source_total || 0);
                const sourceIndexed = Number(state.source_indexed || 0);
                const mediaTotal = Number(state.media_total || 0);
                const mediaScanned = Number(state.media_scanned || 0);
                const sourcePct = sourceTotal > 0 ? Math.min(1, sourceIndexed / sourceTotal) : 1;
                const mediaPct = mediaTotal > 0 ? Math.min(1, mediaScanned / mediaTotal) : 0;
                if (state.phase === 'indexing_sources') return Math.round(sourcePct * 50);
                if (state.phase === 'scanning_media') return 50 + Math.round(mediaPct * 50);
                return 0;
            }

            function updateState(state){
                if (!state) return;
                serverState = state;
                const labels = {
                    idle:'Sin análisis iniciado',
                    indexing_sources:'Indexando fuentes externas',
                    scanning_media:'Comparando con Media',
                    complete:'Análisis completado',
                    error:'Análisis detenido por error'
                };
                const pct = progressPercent(state);
                $('seo-images-cleanup-progress-bar').style.width = pct + '%';
                $('seo-images-cleanup-progress-text').textContent = pct + '%';
                $('seo-images-cleanup-phase').textContent = labels[state.phase] || state.phase || '';
                $('seo-images-cleanup-detail').textContent = 'Fuentes: ' + number(state.source_indexed) + ' / ' + number(state.source_total) + ' · Media: ' + number(state.media_scanned) + ' / ' + number(state.media_total);
                $('seo-images-cleanup-audit-start').textContent = state.status === 'running' ? 'Continuar análisis' : 'Iniciar / actualizar análisis';
            }

            function updateCandidateStats(stats){
                if (!stats) return;
                $('seo-clean-stat-total').textContent = number(stats.total);
                $('seo-clean-stat-safe').textContent = number(stats.safe);
                $('seo-clean-stat-url').textContent = number(stats.BORRAR_URL_ORIGEN_EXACTA);
                $('seo-clean-stat-path').textContent = number(stats.BORRAR_RUTA_EXACTA);
                $('seo-clean-stat-product').textContent = number(stats.BORRAR_MISMO_PRODUCTO);
                $('seo-clean-stat-unique').textContent = number(stats.BORRAR_RUTA_PROVEEDOR_UNICA);
                $('seo-clean-stat-shared').textContent = number(stats.REVISAR_NOMBRE_COMPARTIDO);
            }

            function updateDeleteStats(stats){
                if (!stats) return;
                $('seo-clean-del-pending').textContent = number(stats.pending);
                $('seo-clean-del-deleted').textContent = number(stats.deleted);
                $('seo-clean-del-missing').textContent = number(stats.already_missing);
                $('seo-clean-del-errors').textContent = number(stats.error);
                $('seo-images-cleanup-retry-errors').disabled = Number(stats.error || 0) < 1;
            }

            async function call(action, extra){
                const body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce', nonce);
                Object.keys(extra || {}).forEach(function(key){ body.set(key, String(extra[key])); });
                const response = await fetch(ajaxUrl, {
                    method:'POST',
                    credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body:body.toString()
                });
                let payload;
                try { payload = await response.json(); }
                catch(e){ throw new Error('El servidor no devolvió JSON válido.'); }
                if (!payload.success) {
                    const message = payload.data && payload.data.message ? payload.data.message : 'Error del servidor.';
                    throw new Error(message);
                }
                return payload.data;
            }

            async function auditLoop(){
                if (!auditRunning) return;
                try {
                    const data = await call('seo_images_cleanup_audit_batch');
                    updateState(data.state);
                    updateCandidateStats(data.stats);
                    updateDeleteStats(data.delete_stats);
                    if (data.state && data.state.status === 'complete') {
                        auditRunning = false;
                        $('seo-images-cleanup-audit-start').disabled = false;
                        $('seo-images-cleanup-audit-stop').disabled = true;
                        $('seo-images-cleanup-delete-start').disabled = Number(data.stats.safe || 0) < 1;
                        appendLog('Análisis completado. Recarga la pestaña para ver la muestra actualizada.');
                        return;
                    }
                    if (auditRunning) window.setTimeout(auditLoop, 180);
                } catch(e) {
                    auditRunning = false;
                    $('seo-images-cleanup-audit-start').disabled = false;
                    $('seo-images-cleanup-audit-stop').disabled = true;
                    appendLog('ERROR análisis: ' + e.message);
                }
            }

            $('seo-images-cleanup-audit-start').addEventListener('click', async function(){
                if (auditRunning) return;
                try {
                    if (!serverState || serverState.status !== 'running') {
                        if (!window.confirm('Se recalculará la comparación desde cero. No se borrará ninguna imagen durante el análisis. ¿Continuar?')) return;
                        const data = await call('seo_images_cleanup_start_audit');
                        updateState(data.state);
                        updateCandidateStats(data.stats);
                        updateDeleteStats(data.delete_stats);
                        appendLog('Nuevo análisis iniciado.');
                    } else {
                        appendLog('Reanudando análisis existente.');
                    }
                    auditRunning = true;
                    this.disabled = true;
                    $('seo-images-cleanup-audit-stop').disabled = false;
                    auditLoop();
                } catch(e) {
                    appendLog('ERROR: ' + e.message);
                }
            });

            $('seo-images-cleanup-audit-stop').addEventListener('click', function(){
                auditRunning = false;
                $('seo-images-cleanup-audit-start').disabled = false;
                this.disabled = true;
                appendLog('Análisis pausado en este navegador. El estado queda guardado.');
            });

            async function deleteLoop(){
                if (!deleteRunning) return;
                try {
                    const data = await call('seo_images_cleanup_delete_batch', {confirm:'1'});
                    (data.items || []).forEach(function(item){
                        appendLog('#' + item.attachment_id + ' · ' + item.status + ' · ' + item.decision + ' · ' + item.filename + (item.message ? ' · ' + item.message : ''));
                    });
                    updateDeleteStats(data.stats);
                    if (Number(data.stats.pending || 0) < 1) {
                        deleteRunning = false;
                        $('seo-images-cleanup-delete-start').disabled = true;
                        $('seo-images-cleanup-delete-stop').disabled = true;
                        appendLog('Borrado por lotes finalizado. Errores: ' + number(data.stats.error) + '.');
                        return;
                    }
                    if (deleteRunning) window.setTimeout(deleteLoop, 250);
                } catch(e) {
                    deleteRunning = false;
                    $('seo-images-cleanup-delete-start').disabled = false;
                    $('seo-images-cleanup-delete-stop').disabled = true;
                    appendLog('ERROR borrado: ' + e.message);
                }
            }

            $('seo-images-cleanup-delete-start').addEventListener('click', function(){
                if (deleteRunning) return;
                if (!$('seo-images-cleanup-backup-confirm').checked) {
                    window.alert('Confirma primero que existe una copia de seguridad reciente.');
                    return;
                }
                const pending = $('seo-clean-del-pending').textContent;
                if (!window.confirm('Se eliminarán permanentemente los attachments seguros pendientes mediante WordPress. ¿Continuar?')) return;
                appendLog('Iniciando borrado de coincidencias seguras. Pendientes mostrados: ' + pending + '.');
                deleteRunning = true;
                this.disabled = true;
                $('seo-images-cleanup-delete-stop').disabled = false;
                deleteLoop();
            });

            $('seo-images-cleanup-delete-stop').addEventListener('click', function(){
                deleteRunning = false;
                $('seo-images-cleanup-delete-start').disabled = false;
                this.disabled = true;
                appendLog('Borrado pausado. Puedes reanudarlo después.');
            });

            $('seo-images-cleanup-retry-errors').addEventListener('click', async function(){
                if (!window.confirm('Los errores volverán a quedar pendientes para un nuevo intento. ¿Continuar?')) return;
                try {
                    const data = await call('seo_images_cleanup_retry_errors');
                    updateDeleteStats(data.stats);
                    appendLog('Errores reactivados: ' + number(data.reset) + '.');
                    if (Number(data.stats.pending || 0) > 0 && serverState.status === 'complete') {
                        $('seo-images-cleanup-delete-start').disabled = false;
                    }
                } catch(e) {
                    appendLog('ERROR al reactivar: ' + e.message);
                }
            });
        })();
        </script>
        <?php
    }
}
