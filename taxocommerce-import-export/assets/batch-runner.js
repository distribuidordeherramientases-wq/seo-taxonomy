/* global URLSearchParams */
/**
 * Keep the active CSV queue moving while the importer is open.
 * The server independently launches signed loopbacks when the tab is closed.
 */
(function () {
    'use strict';
    const config = window.tcIeBatchRunner;
    if (!config || !config.ajaxUrl || !config.nonce) { return; }
    const output = document.getElementById('tc-ie-batch-live');
    const progressBar = document.getElementById('tc-ie-import-progress-bar');
    const progressText = document.getElementById('tc-ie-import-progress-text');

    function readableBytes(bytes) {
        const value = Math.max(0, Number(bytes) || 0);
        if (value >= 1048576) { return (value / 1048576).toFixed(2) + ' MB'; }
        if (value >= 1024) { return (value / 1024).toFixed(1) + ' KB'; }
        return value + ' bytes';
    }

    function showProgress(data) {
        const progress = data.progress || {};
        const available = progress.available === true;
        const percent = available ? Math.max(0, Math.min(100, Number(progress.percent) || 0)) : 0;
        if (progressBar) {
            progressBar.value = percent;
            progressBar.setAttribute('aria-valuetext', available
                ? percent.toFixed(1) + '% del archivo leído'
                : 'Sin archivo en proceso');
        }
        if (progressText) {
            progressText.textContent = available
                ? percent.toFixed(1) + '% · ' + readableBytes(progress.bytes_read)
                    + ' leídos de ' + readableBytes(progress.bytes_total)
                    + ' · ' + readableBytes(progress.bytes_remaining) + ' restantes'
                    + ' · ' + Number(data.pending_files || 0) + ' CSV pendientes'
                : 'No hay CSV activo · ' + Number(data.pending_files || 0) + ' CSV pendientes';
        }
    }

    let running = false;
    let last = '';
    let failed = 0;
    let stopped = false;

    function show(data) {
        if (!data) { return; }
        showProgress(data);
        if (!output) { return; }
        const text = [
            'Estado: ' + (data.status || 'sin datos'),
            'Archivo: ' + (data.file || '—'),
            'Filas: ' + Number(data.rows || 0),
            'Último lote: ' + Number(data.last_batch || 0) + ' filas de '
                + Number(data.target_batch || 0) + ' objetivo en '
                + Number(data.elapsed || 0).toFixed(2) + ' s',
            'Siguiente lote: ' + Number(data.next_batch || 0) + ' filas',
            'Rendimiento: ' + Number(data.rows_per_second || 0).toFixed(2) + ' filas/s',
            'Tiempo medio/fila: ' + Number(data.average_row_seconds || 0).toFixed(2) + ' s',
            'Guardados WooCommerce: ' + Number(data.row_save_seconds || 0).toFixed(2) + ' s/lote',
            'SQL: ' + Number(data.db_queries_per_row || 0).toFixed(1) + ' consultas/fila',
            'Guardados omitidos (sin cambios): ' + Number(data.product_skipped_unchanged_save || 0),
            'Guardados ejecutados: ' + Number(data.product_saved || 0),
            'Consultas por fase: búsqueda ' + Number(data.product_lookup_sql || 0)
                + ', WooCommerce ' + Number(data.product_save_sql || 0)
                + ', metadatos ' + Number(data.product_meta_sql || 0),
            'Tiempo por fase: búsqueda ' + Number(data.product_lookup_seconds || 0).toFixed(2)
                + ' s, WooCommerce ' + Number(data.product_save_seconds || 0).toFixed(2)
                + ' s, metadatos ' + Number(data.product_meta_seconds || 0).toFixed(2) + ' s',
            'Memoria PHP: ' + Number(data.memory_percent || 0).toFixed(1) + '%',
            'CPU PHP (no servidor): ' + Number(data.cpu_percent || 0).toFixed(1) + '%',
            'Regulación: ' + (data.reason || 'sin muestra'),
            data.message || ''
        ].join(' · ');
        if (last !== text) {
            output.textContent = text;
            last = text;
        }
    }

    function step() {
        if (stopped || running) { return; }
        running = true;
        const body = new URLSearchParams({
            action: 'taxocommerce_ie_batch_step',
            nonce: config.nonce
        });
        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (res) {
                if (!res || !res.success) { throw new Error('Respuesta incorrecta de WordPress'); }
                failed = 0;
                show(res.data);
                if (res.data && res.data.enabled) {
                    // As soon as one bounded request completes, issue the next.
                    // No artificial 1-15 second delays between successful lots.
                    window.setTimeout(step, res.data.busy ? 500 : 0);
                } else {
                    stopped = true;
                }
            })
            .catch(function (error) {
                failed++;
                if (output) {
                    output.textContent = 'No se pudo continuar desde esta pestaña: '
                        + error.message + ' · Reintento ' + failed + '/5. '
                        + 'La recuperación del servidor sigue disponible.';
                }
                if (failed <= 5) {
                    window.setTimeout(step, Math.min(5000, failed * 1000));
                } else {
                    stopped = true;
                }
            })
            .finally(function () { running = false; });
    }
    // Allow microtask completion before first request.
    window.setTimeout(step, 0);
}());
